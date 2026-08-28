<?php

declare(strict_types=1);

namespace Rerm\Admin;

use PDO;
use Rerm\App;
use Rerm\Audit\Action;
use Rerm\Audit\AuditLog;
use Rerm\Auth\Access;
use Rerm\Auth\Capability;
use Rerm\Auth\Level;
use Rerm\Auth\Password;
use Rerm\Auth\Subject;
use Rerm\Auth\TokenStore;
use Rerm\Auth\User;
use Rerm\Roster\RosterPage;

/**
 * The Designate Users writes (spec 4.4, 7.5) — the FIRST code in this
 * application ever to write `app_user.granted_level` or a scope override.
 * Only migration 003 had touched either column before now.
 *
 * Three actions, one entry point, shaped like Rerm\Roster\AssignOfficers: a
 * small outcome vocabulary the handler turns into a flash and a 303, every
 * permission question asked again here whatever the form offered, and the
 * database read fresh before anything is written.
 *
 *   grant     set granted_level, creating the login if there is none
 *   revoke    clear it, and let the title-derived level stand again
 *   scope     set or clear an Admin-only scope override
 *
 * Four rules the shape enforces:
 *
 *   * **Two permission questions, not one.** `Access::allows(...,
 *     DesignateAllowedUser, $subject)` asks whether this actor may designate
 *     THIS MEMBER — scope. `Access::mayGrant($user, $level)` asks whether
 *     they may hand out THIS LEVEL — the spec 4.4 cap. They are different
 *     questions with different answers and both have to be yes.
 *   * **A grant is durable and this file is the only thing that writes it.**
 *     An import rewrites `member.title`, `member.title_level` and
 *     `app_user.level`, and never `granted_level` (spec 6.6). That boundary
 *     is what makes a designation survive the roster refresh that demoted
 *     the person, which is the entire point of the feature.
 *   * **Revocation is capped by the GRANTED level, not the target's.** Phase
 *     8 decided 2: revocable by anyone who could have granted it. So a Senior
 *     Officer may take back an Officer-level grant an Admin made, and may not
 *     touch an Executive one. The symmetry is deliberate — the same person
 *     who can hand out access can take it back without escalating.
 *   * **Designating never sends email.** `mail.enabled` ships false and CI
 *     fails the build if the committed defaults could deliver; the initial
 *     password is spoken aloud by whoever did the designating, exactly as
 *     spec 3.1 route 3 intends. Nothing here touches Rerm\Mail.
 */
final class Designate
{
    /**
     * The most teams one scope may name. The committee has 96 and this is
     * the whole of it — anything past it is a crafted request, not somebody
     * ticking boxes, and it is TRIMMED rather than refused because a scope
     * that names every team is the same as naming none.
     */
    public const MAX_TEAM_SCOPE = 96;

    /** The actions a request may name. Anything else is refused unread. */
    public const ACTIONS = ['grant', 'revoke', 'scope', 'reset_password', 'team_scope'];

    /**
     * Every outcome apply() can return. Declared rather than implied: the
     * handler turns each one into a sentence, and an outcome it does not know
     * about would reach the actor as the wrong sentence.
     */
    public const OUTCOMES = [
        'granted', 'revoked', 'scope_set', 'scope_cleared', 'unchanged',
        'bad_level', 'bad_action', 'refused', 'not_found', 'nothing_to_revoke',
        'bad_scope', 'password_reset', 'no_account',
        'team_scope_set', 'team_scope_cleared', 'not_senior',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Password $passwords,
        private readonly string $initialPassword,
        private readonly TokenStore $tokens,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self(
            $app->db(),
            Password::fromApp($app),
            (string) $app->config()->get('auth.default_password', '1234'),
            // Phase 8.5: an Admin reset kills every session for the account,
            // which is the same revocation the emailed reset already does.
            TokenStore::fromApp($app),
        );
    }

    /**
     * $input is the POST body, route-shaped and untrusted:
     *
     *   action              one of ACTIONS
     *   member_id           the member being designated
     *   level               the level to grant (grant only)
     *   scope_division_id   '' clears; otherwise a division id (scope only)
     *   scope_team_id       '' clears; otherwise a team id (scope only)
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function apply(User $user, array $input): array
    {
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';

        $result = [
            'action'      => $action,
            'outcome'     => 'bad_action',
            'member_name' => '',
            'level'       => null,
            'created'     => false,
            'reactivated' => false,
            'scope'       => '',
            'teams'       => [],
        ];

        if (!in_array($action, self::ACTIONS, true)) {
            return $result;
        }

        $memberId = (int) ($input['member_id'] ?? 0);
        $member   = $memberId > 0 ? $this->member($memberId) : null;

        // An out-of-scope or non-existent member gets the same answer, and
        // the handler turns it into the 404 a typed URL would get: this
        // application does not discuss what exists with people who cannot
        // see it.
        if ($member === null) {
            $result['outcome'] = 'not_found';

            return $result;
        }

        $result['member_name'] = $member['name'];

        // Scope, asked of the member's OWN row (spec 4.3): teams span
        // divisions, so placement is a property of the person.
        if (!Access::allows($user, Capability::DesignateAllowedUser, $member['subject'])) {
            $result['outcome'] = 'refused';

            return $result;
        }

        if ($action === 'grant') {
            return $this->grant($user, $member, $input, $result);
        }

        if ($action === 'revoke') {
            return $this->revoke($user, $member, $result);
        }

        if ($action === 'reset_password') {
            return $this->resetPassword($user, $member, $result);
        }

        if ($action === 'team_scope') {
            return $this->teamScope($user, $member, $input, $result);
        }

        return $this->scope($user, $member, $input, $result);
    }

    /**
     * Set a granted level, creating the account if there is none.
     *
     * @param array<string, mixed> $member
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function grant(User $user, array $member, array $input, array $result): array
    {
        $level = Level::tryFrom(is_string($input['level'] ?? null) ? $input['level'] : '');

        // The spec 4.4 cap, asked of the LEVEL. An unknown string and a level
        // above the actor's rank get the same refusal, because "what may I
        // grant" has one answer and it is Access::mayGrant().
        if ($level === null || !Access::mayGrant($user, $level)) {
            $result['outcome'] = 'bad_level';

            return $result;
        }

        $result['level'] = $level;

        if ($member['granted_level'] === $level->value) {
            $result['outcome'] = 'unchanged';

            return $result;
        }

        $before = [
            'granted_level'   => $member['granted_level'],
            'title_level'     => $member['title_level'],
            'effective_level' => $member['effective_level'],
            'is_active'       => $member['is_active'],
        ];

        $this->pdo->beginTransaction();

        try {
            if ($member['user_id'] === null) {
                // Spec 3.1 route 3: a designation creates the login the same
                // way an import does — initial password 1234, hashed like any
                // other because a column that sometimes holds plaintext is a
                // column something will one day compare as plaintext, and
                // must_change_password set so it cannot be used as it stands.
                //
                // `level` is seeded from the member's OWN title level, not
                // from the grant: it is the title-derived half, and a revoke
                // has to leave the right thing standing behind it.
                $create = $this->pdo->prepare(
                    'INSERT INTO app_user'
                    . ' (member_id, level, granted_level, granted_by, granted_at,'
                    . '  password_hash, must_change_password, is_active)'
                    . ' VALUES (:member, :level, :granted, :by, UTC_TIMESTAMP(), :hash, 1, 1)'
                );
                $create->execute([
                    ':member'  => $member['id'],
                    ':level'   => $member['title_level'],
                    ':granted' => $level->value,
                    ':by'      => $user->id,
                    ':hash'    => $this->passwords->hash($this->initialPassword),
                ]);

                $result['created'] = true;
            } else {
                // A grant means access, so it reopens an account a demotion
                // closed — that is what "durable" has to mean in practice.
                // The row is reactivated, never recreated: the audit trail
                // and every contact_log row point at this id.
                $update = $this->pdo->prepare(
                    'UPDATE app_user SET granted_level = :granted, granted_by = :by,'
                    . ' granted_at = UTC_TIMESTAMP(), is_active = 1 WHERE id = :id'
                );
                $update->execute([
                    ':granted' => $level->value,
                    ':by'      => $user->id,
                    ':id'      => $member['user_id'],
                ]);

                $result['reactivated'] = $member['is_active'] === 0;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->audit($user, Action::GrantLevel, $member, $before, [
            'granted_level'   => $level->value,
            'effective_level' => $level->value,
            'granted_by'      => $user->id,
            'account_created' => $result['created'],
        ]);

        $result['outcome'] = 'granted';

        return $result;
    }

    /**
     * Clear a granted level. The title-derived level stands again, and with
     * it the account's own fate.
     *
     * @param array<string, mixed> $member
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function revoke(User $user, array $member, array $result): array
    {
        if ($member['granted_level'] === null) {
            $result['outcome'] = 'nothing_to_revoke';

            return $result;
        }

        $granted = Level::from((string) $member['granted_level']);

        // Decided 2: capped by the GRANTED level, so revocation is available
        // to exactly the people who could have made the grant.
        if (!Access::mayGrant($user, $granted)) {
            $result['outcome'] = 'refused';

            return $result;
        }

        $result['level'] = $granted;

        $title = Level::from((string) $member['title_level']);

        // The demotion rule (spec 6.6), applied here for the same reason an
        // import applies it: with the grant gone, the title level decides
        // whether an account exists at all. A Committee Member whose grant is
        // revoked is deactivated, NEVER deleted — the audit trail outlives
        // the account, and a later grant reactivates this same row.
        $active = $title->grantsLogin() ? 1 : 0;

        $before = [
            'granted_level'   => $member['granted_level'],
            'title_level'     => $member['title_level'],
            'effective_level' => $member['effective_level'],
            'is_active'       => $member['is_active'],
        ];

        $this->pdo->prepare(
            'UPDATE app_user SET granted_level = NULL, granted_by = NULL, granted_at = NULL,'
            . ' is_active = :active WHERE id = :id'
        )->execute([':active' => $active, ':id' => $member['user_id']]);

        $this->audit($user, Action::RevokeLevel, $member, $before, [
            'granted_level'   => null,
            'effective_level' => $title->value,
            'is_active'       => $active,
        ]);

        $result['outcome'] = 'revoked';

        return $result;
    }

    /**
     * Reset somebody else's password to the shipped initial one (Phase 8.5).
     *
     * The gap this fills: `/password` is self-service, `/forgot` needs mail —
     * and `mail.enabled` ships false, so on the live server it delivers
     * nothing — and `bin/set-admin-password.php` covers the seeded master
     * admin alone. An officer who forgot their password had no route back in.
     *
     * **Capped by Access::mayGrant() against the TARGET'S effective level,
     * and that is the load-bearing line in this method.** Resetting a password
     * to a value the actor knows is equivalent to taking the account: without
     * the cap a Senior Officer could reset an Admin's password and then sign
     * in as them, which is a straight privilege escalation through a button
     * labelled "help". The same rank rule that decides who may GRANT a level
     * decides who may seize one.
     *
     * Two more refusals, each for its own reason:
     *
     *   * **No account, nothing to reset.** A member with no login has no
     *     password; the Admin wanted `grant`, and the outcome says so rather
     *     than silently doing nothing.
     *   * **Never a system row.** The master administrator has `/setup` and
     *     `bin/set-admin-password.php`. Letting a web screen seize it would
     *     widen the blast radius of one stolen Admin session for no benefit
     *     — it is refused by the member read below, which already excludes
     *     `is_system`, and asserted by a test so the exclusion cannot be
     *     loosened by accident.
     *
     * Every session dies with the password. Spec 3.2 already requires that of
     * a voluntary change; a reset the account holder did not ask for is not
     * the weaker case. Without it, whoever is signed in on the old password
     * stays signed in — which, if the reset was a response to a compromise,
     * is exactly the session that must not survive.
     *
     * Nothing is emailed, and nothing here touches Rerm\Mail.
     *
     * @param array<string, mixed> $member
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function resetPassword(User $user, array $member, array $result): array
    {
        if ($member['user_id'] === null) {
            $result['outcome'] = 'no_account';

            return $result;
        }

        // What the target can currently DO — granted_level ?? title_level,
        // read off the schema's own VIRTUAL column rather than re-derived.
        $effective = Level::from(
            (string) ($member['effective_level'] ?? $member['title_level'])
        );

        if (!Access::mayGrant($user, $effective)) {
            $result['outcome'] = 'refused';

            return $result;
        }

        $result['level'] = $effective;

        $this->pdo->prepare(
            'UPDATE app_user SET password_hash = :hash, must_change_password = 1,'
            . ' password_changed_at = UTC_TIMESTAMP() WHERE id = :id'
        )->execute([
            ':hash' => $this->passwords->hash($this->initialPassword),
            ':id'   => $member['user_id'],
        ]);

        // ALL of them, with no exception: none of these sessions belongs to
        // the person standing at this screen.
        $this->tokens->revokeAllFor((int) $member['user_id']);

        $this->audit($user, Action::PasswordResetByAdmin, $member, [
            'must_change_password' => $member['must_change'] ?? null,
        ], [
            'password'             => 'reset to the initial password',
            'must_change_password' => 1,
            'sessions'             => 'all revoked',
            'target_level'         => $effective->value,
        ]);

        $result['outcome'] = 'password_reset';

        return $result;
    }

    /**
     * The Admin-only scope override (spec 4.4).
     *
     * It is the only mechanism that can point a Senior Officer at a division
     * other than their own — which is what gives the 72 members in
     * `(No Division)` an owner (spec 5.1a), since nobody's own Subcommittee 3
     * puts them there on purpose.
     *
     * Gated on Capability::DesignateAdmin, the matrix's Admin/Everywhere
     * capability for the Admin-only half of this screen. Not a new capability:
     * the Phase 8 brief is explicit that no capability is added, and this is
     * the same question — is this actor an Admin, doing the part of Designate
     * Users only an Admin may do.
     *
     * @param array<string, mixed> $member
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function scope(User $user, array $member, array $input, array $result): array
    {
        if (!Access::mayUse($user, Capability::DesignateAdmin)) {
            $result['outcome'] = 'refused';

            return $result;
        }

        // No account, nothing to scope: the columns live on app_user, and a
        // member with no login has no scope to override.
        if ($member['user_id'] === null) {
            $result['outcome'] = 'refused';

            return $result;
        }

        $divisionId = self::optionalId($input['scope_division_id'] ?? '');
        $teamId     = self::optionalId($input['scope_team_id'] ?? '');

        // An id that names nothing is refused rather than stored: a dangling
        // override would be a scope that silently resolves to nobody, and the
        // foreign key would refuse it anyway with a message nobody can read.
        if (($divisionId !== null && !$this->exists('division', $divisionId))
            || ($teamId !== null && !$this->exists('team', $teamId))
        ) {
            $result['outcome'] = 'bad_scope';

            return $result;
        }

        if ($divisionId === $member['scope_division_id'] && $teamId === $member['scope_team_id']) {
            $result['outcome'] = 'unchanged';

            return $result;
        }

        $before = [
            'scope_division_id' => $member['scope_division_id'],
            'scope_team_id'     => $member['scope_team_id'],
        ];

        $this->pdo->prepare(
            'UPDATE app_user SET scope_division_id = :division, scope_team_id = :team WHERE id = :id'
        )->execute([
            ':division' => $divisionId,
            ':team'     => $teamId,
            ':id'       => $member['user_id'],
        ]);

        $this->audit($user, Action::SetScopeOverride, $member, $before, [
            'scope_division_id' => $divisionId,
            'scope_team_id'     => $teamId,
        ]);

        $result['outcome'] = ($divisionId === null && $teamId === null) ? 'scope_cleared' : 'scope_set';

        return $result;
    }

    /**
     * The teams a Senior Officer is narrowed to (Phase 8.5).
     *
     * The shape spec 4.3 did not have: some Vice Chairmen cover three teams
     * and some cover one, and neither "a whole division" nor "a single team"
     * describes the first. An empty selection clears the narrowing, which
     * puts them back on whatever their title and any division override say —
     * for a Vice Chairman, their own team; for everybody else, their division.
     *
     * **Senior Officer only** (settled with the owner, 28 August). An Officer
     * already has a working single-team scope and a second shape at that
     * level is untested surface nobody asked for; an Executive Officer and an
     * Admin see everything, so a narrowing would be a WHERE clause on a query
     * that should have none. Both are refused as `not_senior` rather than
     * silently ignored, so the Admin finds out.
     *
     * Admin-only, like the division override beside it, and for the same
     * reason: spec 4.4 says an Admin sets a scope.
     *
     * @param array<string, mixed> $member
     * @param array<string, mixed> $input
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function teamScope(User $user, array $member, array $input, array $result): array
    {
        if (!Access::mayUse($user, Capability::DesignateAdmin)) {
            $result['outcome'] = 'refused';

            return $result;
        }

        if ($member['user_id'] === null) {
            $result['outcome'] = 'no_account';

            return $result;
        }

        // What the target can DO decides whether this shape applies to them,
        // read off the schema's VIRTUAL column rather than re-derived.
        $effective = $member['effective_level'] !== null
            ? Level::from((string) $member['effective_level'])
            : null;

        if ($effective !== Level::SeniorOfficer) {
            $result['outcome'] = 'not_senior';

            return $result;
        }

        // Digits only, de-duplicated, and capped: max_input_vars is 1000 on
        // this host with SILENT truncation, and 96 teams is the whole
        // committee, so anything past the cap is a crafted request rather
        // than a person ticking boxes.
        $wanted = [];
        foreach ((array) ($input['team_scope'] ?? []) as $value) {
            if ((is_string($value) || is_int($value)) && ctype_digit((string) $value)) {
                $teamId = (int) $value;
                if ($teamId > 0) {
                    $wanted[$teamId] = $teamId;
                }
            }
        }
        $wanted = array_slice(array_values($wanted), 0, self::MAX_TEAM_SCOPE);

        // A team id that names nothing is refused rather than stored — the
        // foreign key would refuse it anyway, with a message nobody can read.
        foreach ($wanted as $teamId) {
            if (!$this->exists('team', $teamId)) {
                $result['outcome'] = 'bad_scope';

                return $result;
            }
        }

        $before = $this->teamScopeFor((int) $member['user_id']);
        sort($before);
        $after = $wanted;
        sort($after);

        if ($before === $after) {
            $result['outcome'] = 'unchanged';

            return $result;
        }

        // Replace wholesale, in one transaction: a half-applied scope is a
        // person seeing some of the teams they should and none of the ones
        // they were about to be given.
        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('DELETE FROM app_user_team WHERE app_user_id = :id')
                ->execute([':id' => $member['user_id']]);

            if ($after !== []) {
                $add = $this->pdo->prepare(
                    'INSERT INTO app_user_team (app_user_id, team_id, granted_by)'
                    . ' VALUES (:user, :team, :by)'
                );
                foreach ($after as $teamId) {
                    $add->execute([
                        ':user' => $member['user_id'],
                        ':team' => $teamId,
                        ':by'   => $user->id,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $this->audit($user, Action::SetTeamScope, $member, ['teams' => $before], ['teams' => $after]);

        $result['teams']   = $after;
        $result['outcome'] = $after === [] ? 'team_scope_cleared' : 'team_scope_set';

        return $result;
    }

    /**
     * The teams currently recorded against an account.
     *
     * @return array<int, int>
     */
    private function teamScopeFor(int $userId): array
    {
        $read = $this->pdo->prepare(
            'SELECT team_id FROM app_user_team WHERE app_user_id = :id ORDER BY team_id'
        );
        $read->execute([':id' => $userId]);

        return array_map('intval', $read->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The member and their account, read fresh. Visible members only —
     * ScopedQuery::visible(), the same three columns every roster read
     * respects — so a purged or dropped member cannot be designated. Scope is
     * NOT applied here: that is Access's question, asked next against this
     * row, so the refusal is decided by the matrix rather than by the query.
     *
     * @return ?array<string, mixed>
     */
    private function member(int $memberId): ?array
    {
        $read = $this->pdo->prepare(
            'SELECT m.id, m.member_number, m.first_name, m.last_name, m.preferred_name,'
            . ' m.title_level, m.division_id, m.team_id,'
            . ' u.id AS user_id, u.granted_level, u.is_active, u.effective_level,'
            . ' u.must_change_password, u.scope_division_id, u.scope_team_id'
            . ' FROM member m LEFT JOIN app_user u ON u.member_id = m.id'
            . ' WHERE m.id = :id AND ' . \Rerm\Roster\ScopedQuery::visible('m')
        );
        $read->execute([':id' => $memberId]);
        $row = $read->fetch();

        if (!is_array($row)) {
            return null;
        }

        return [
            'id'            => (int) $row['id'],
            'member_number' => (string) $row['member_number'],
            'name'          => RosterPage::displayName(
                (string) $row['preferred_name'],
                (string) $row['first_name'],
                (string) $row['last_name'],
                (string) $row['member_number']
            ),
            'title_level'       => (string) $row['title_level'],
            'user_id'           => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'granted_level'     => $row['granted_level'] !== null ? (string) $row['granted_level'] : null,
            'effective_level'   => $row['effective_level'] !== null ? (string) $row['effective_level'] : null,
            'is_active'         => $row['user_id'] !== null ? (int) $row['is_active'] : 0,
            'must_change'       => $row['user_id'] !== null ? (int) $row['must_change_password'] : null,
            'scope_division_id' => $row['scope_division_id'] !== null ? (int) $row['scope_division_id'] : null,
            'scope_team_id'     => $row['scope_team_id'] !== null ? (int) $row['scope_team_id'] : null,
            'subject'           => Subject::fromMemberRow($row),
        ];
    }

    /** Does this reference row exist? The table name is a literal, never input. */
    private function exists(string $table, int $id): bool
    {
        $sql = match ($table) {
            'division' => 'SELECT 1 FROM division WHERE id = :id',
            'team'     => 'SELECT 1 FROM team WHERE id = :id',
        };

        $read = $this->pdo->prepare($sql);
        $read->execute([':id' => $id]);

        return $read->fetchColumn() !== false;
    }

    /**
     * A form value that is either an id or a deliberate clearing.
     *
     * '' and '0' both mean "no override" — the select's empty option — and
     * anything non-numeric means the same, because a scope that cannot be
     * read is a scope that must not be stored.
     */
    private static function optionalId(mixed $value): ?int
    {
        if (!is_scalar($value)) {
            return null;
        }

        $id = (int) (string) $value;

        return $id > 0 ? $id : null;
    }

    /**
     * The paper trail (spec 4.4: "Every grant and revocation writes to
     * audit_log with the actor, the target, the level and the timestamp").
     * The timestamp is the column's own default.
     *
     * @param array<string, mixed> $member
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function audit(User $user, Action $action, array $member, array $before, array $after): void
    {
        (new AuditLog($this->pdo))->record(
            $user,
            $action,
            'app_user',
            // The MEMBER number, not the app_user id: an audit row outlives
            // the account, and "who was this" has to stay answerable when the
            // row it described has been deactivated for two years.
            (string) $member['member_number'],
            $before,
            $after
        );
    }
}
