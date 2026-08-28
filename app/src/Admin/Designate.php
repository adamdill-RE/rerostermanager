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
    /** The actions a request may name. Anything else is refused unread. */
    public const ACTIONS = ['grant', 'revoke', 'scope'];

    /**
     * Every outcome apply() can return. Declared rather than implied: the
     * handler turns each one into a sentence, and an outcome it does not know
     * about would reach the actor as the wrong sentence.
     */
    public const OUTCOMES = [
        'granted', 'revoked', 'scope_set', 'scope_cleared', 'unchanged',
        'bad_level', 'bad_action', 'refused', 'not_found', 'nothing_to_revoke',
        'bad_scope',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Password $passwords,
        private readonly string $initialPassword,
    ) {
    }

    public static function fromApp(App $app): self
    {
        return new self(
            $app->db(),
            Password::fromApp($app),
            (string) $app->config()->get('auth.default_password', '1234'),
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
     * The member and their account, read fresh. Visible members only —
     * ScopedQuery::visible(), the same three columns every roster read
     * respects — so a purged or absent member cannot be designated. Scope is
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
            . ' u.scope_division_id, u.scope_team_id'
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
