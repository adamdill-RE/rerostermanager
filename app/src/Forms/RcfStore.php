<?php

declare(strict_types=1);

namespace Rerm\Forms;

use DateTimeImmutable;
use PDO;
use Rerm\App;
use Rerm\Auth\User;

/**
 * The kept Roster Change Form (Phase 11, spec-v2 §12): what is written when
 * one is produced, and what is read back when one is produced AGAIN.
 *
 * Phase 9 built the file, sent it, unlinked it and kept one audit row. The
 * first real user of the feature asked the question that row cannot answer —
 * "was an RCF ever submitted for this member, and where did it stop" — and
 * answering it means keeping the form. So this writes `rcf` and `rcf_row`
 * in the same request that sends the file, after the file has been built
 * and before its body goes out: a row here means a form really left.
 *
 * **It stores what was PRINTED, not what was picked.** The submitter as
 * "Name, Title", the sub-committee as "Division - Team", every cell of every
 * filled row exactly as `RosterChangeForm::draw()` wrote it. A regenerated
 * form therefore reads the same as the original even if the officer has
 * since changed title, the team has been renamed, or the member is no longer
 * on the roster — which they will not be, if the form was a removal and it
 * worked. A form is a request; the roster changes because the request was
 * granted, and the record of the request must not change with it.
 *
 * **Nothing here reads the roster to write the roster.** `member_id` is
 * resolved from the number the officer typed, once, so the member card can
 * list the forms about a person; it is a link, and the card it links to
 * re-checks scope like every other read. Nothing writes `member`.
 */
final class RcfStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function fromApp(App $app): self
    {
        return new self($app->db());
    }

    /**
     * Keeps a form that has just been built, and returns its id.
     *
     * One transaction: the form and its rows land together or not at all.
     * Blank rows are not stored — a three-person form is three rows here,
     * at the positions they were typed in, and `form()` prints blank
     * between them exactly as the original did.
     *
     * @param array<string, mixed> $form exactly what RcfPage::formFromInput() returned
     */
    public function store(User $actor, int $showYearId, array $form): int
    {
        $entries = array_values((array) $form['entries']);
        $filled  = [];
        foreach ($entries as $index => $entry) {
            if (!RosterChangeForm::entryIsBlank($entry)) {
                $filled[$index + 1] = $entry;
            }
        }

        $members = $this->memberIds(array_map(
            static fn (array $entry): string => (string) ($entry['member_number'] ?? ''),
            $filled
        ));

        $this->pdo->beginTransaction();

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO rcf (show_year_id, generated_by, year_label, submitter, submitter_number,'
                . ' form_date, subcommittee, division_id, team_id, row_count)'
                . ' VALUES (:year, :by, :label, :submitter, :number, :date, :subcommittee,'
                . ' :division, :team, :rows)'
            );
            $insert->execute([
                ':year'         => $showYearId,
                ':by'           => $actor->id,
                ':label'        => mb_substr((string) $form['year'], 0, 32),
                ':submitter'    => mb_substr((string) $form['submitter'], 0, 255),
                ':number'       => mb_substr((string) ($form['submitter_number'] ?? ''), 0, 32),
                ':date'         => self::isoDate((string) ($form['form_date'] ?? '')),
                ':subcommittee' => mb_substr((string) $form['subcommittee'], 0, 255),
                ':division'     => $form['division_id'] ?? null,
                ':team'         => $form['team_id'] ?? null,
                ':rows'         => count($filled),
            ]);
            $rcfId = (int) $this->pdo->lastInsertId();

            if ($filled !== []) {
                $places = [];
                $bind   = [];
                $i      = 0;
                foreach ($filled as $position => $entry) {
                    $number = (string) ($entry['member_number'] ?? '');

                    $places[] = "(:rcf{$i}, :pos{$i}, :mid{$i}, :num{$i}, :name{$i}, :type{$i}, :rookie{$i},"
                        . " :newt{$i}, :prevt{$i}, :wait{$i}, :reason{$i}, :sub{$i}, :sponsor{$i})";

                    $bind[":rcf{$i}"]     = $rcfId;
                    $bind[":pos{$i}"]     = $position;
                    $bind[":mid{$i}"]     = $members[$number] ?? null;
                    $bind[":num{$i}"]     = mb_substr($number, 0, 32);
                    $bind[":name{$i}"]    = mb_substr((string) ($entry['member_name'] ?? ''), 0, 160);
                    $bind[":type{$i}"]    = mb_substr((string) ($entry['type'] ?? ''), 0, 8);
                    $bind[":rookie{$i}"]  = (string) ($entry['rookie'] ?? '') === RosterChangeForm::TICKED ? 1 : 0;
                    $bind[":newt{$i}"]    = mb_substr((string) ($entry['new_title'] ?? ''), 0, 160);
                    $bind[":prevt{$i}"]   = mb_substr((string) ($entry['previous_title'] ?? ''), 0, 160);
                    $bind[":wait{$i}"]    = (string) ($entry['wait_list'] ?? '') === RosterChangeForm::TICKED ? 1 : 0;
                    $bind[":reason{$i}"]  = mb_substr((string) ($entry['remove_reason'] ?? ''), 0, 8);
                    $bind[":sub{$i}"]     = mb_substr((string) ($entry['new_subcommittee'] ?? ''), 0, 160);
                    $bind[":sponsor{$i}"] = mb_substr((string) ($entry['sponsor'] ?? ''), 0, 160);
                    $i++;
                }

                $this->pdo->prepare(
                    'INSERT INTO rcf_row (rcf_id, position, member_id, member_number, member_name,'
                    . ' type, rookie, new_title, previous_title, wait_list, remove_reason,'
                    . ' new_subcommittee, sponsor) VALUES ' . implode(', ', $places)
                )->execute($bind);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $rcfId;
    }

    /**
     * A kept form as the argument `RosterChangeForm::build()` takes — the
     * same shape `RcfPage::formFromInput()` produces, rebuilt from what was
     * printed rather than from the roster as it now stands. Null for an id
     * nothing was kept under.
     *
     * The caller has already decided the caller may see it
     * (`RcfTracking::one()`); this reads by id and nothing else.
     *
     * @return ?array{year: string, submitter: string, date: string, subcommittee: string,
     *     entries: array<int, array<string, string>>}
     */
    public function form(int $rcfId): ?array
    {
        $read = $this->pdo->prepare(
            'SELECT year_label, submitter, form_date, subcommittee FROM rcf WHERE id = :id'
        );
        $read->execute([':id' => $rcfId]);
        $rcf = $read->fetch();
        if (!is_array($rcf)) {
            return null;
        }

        // Twenty-five entries, blank ones carrying an UNTICKED box in both
        // checkbox columns — exactly the shape RcfPage::formFromInput() hands
        // the writer, so the same input draws the same sheet.
        $entries = [];
        for ($i = 0; $i < RosterChangeForm::ROWS; $i++) {
            $entries[$i] = array_replace(RosterChangeForm::emptyEntry(), [
                'rookie'    => RosterChangeForm::UNTICKED,
                'wait_list' => RosterChangeForm::UNTICKED,
            ]);
        }

        $rows = $this->pdo->prepare(
            'SELECT position, member_number, member_name, type, rookie, new_title, previous_title,'
            . ' wait_list, remove_reason, new_subcommittee, sponsor'
            . ' FROM rcf_row WHERE rcf_id = :id ORDER BY position'
        );
        $rows->execute([':id' => $rcfId]);

        foreach ($rows->fetchAll() as $row) {
            $index = (int) $row['position'] - 1;
            if ($index < 0 || $index >= RosterChangeForm::ROWS) {
                continue;
            }

            $entries[$index] = [
                'type'             => (string) $row['type'],
                'rookie'           => (int) $row['rookie'] === 1 ? RosterChangeForm::TICKED : RosterChangeForm::UNTICKED,
                'member_name'      => (string) $row['member_name'],
                'member_number'    => (string) $row['member_number'],
                'new_title'        => (string) $row['new_title'],
                'previous_title'   => (string) $row['previous_title'],
                'wait_list'        => (int) $row['wait_list'] === 1 ? RosterChangeForm::TICKED : RosterChangeForm::UNTICKED,
                'remove_reason'    => (string) $row['remove_reason'],
                'new_subcommittee' => (string) $row['new_subcommittee'],
                'sponsor'          => (string) $row['sponsor'],
            ];
        }

        return [
            'year'         => (string) $rcf['year_label'],
            'submitter'    => (string) $rcf['submitter'],
            'date'         => self::american((string) ($rcf['form_date'] ?? '')),
            'subcommittee' => (string) $rcf['subcommittee'],
            'entries'      => $entries,
        ];
    }

    /**
     * Records that a kept form was downloaded again. The audit row is the
     * record; this is the count the form's own page prints.
     */
    public function regenerated(int $rcfId): void
    {
        $this->pdo->prepare(
            'UPDATE rcf SET regenerated_count = regenerated_count + 1,'
            . ' last_regenerated_at = UTC_TIMESTAMP() WHERE id = :id'
        )->execute([':id' => $rcfId]);
    }

    /**
     * member_number => member.id, for the numbers that name somebody. One
     * query, unscoped on purpose: the id is a link for the member card, and
     * the card re-checks scope. A number nobody has yields nothing, and the
     * row keeps the number.
     *
     * @param array<int, string> $numbers
     * @return array<string, int>
     */
    private function memberIds(array $numbers): array
    {
        $numbers = array_values(array_unique(array_filter(
            $numbers,
            static fn (string $n): bool => $n !== ''
        )));
        if ($numbers === []) {
            return [];
        }

        $places = [];
        $bind   = [];
        foreach ($numbers as $i => $number) {
            $places[]        = ":n{$i}";
            $bind[":n{$i}"] = $number;
        }

        $read = $this->pdo->prepare(
            'SELECT id, member_number FROM member WHERE member_number IN (' . implode(', ', $places) . ')'
        );
        $read->execute($bind);

        $ids = [];
        foreach ($read->fetchAll() as $row) {
            $ids[(string) $row['member_number']] = (int) $row['id'];
        }

        return $ids;
    }

    /** `YYYY-MM-DD` when it is one, else null — a DATE column takes no guess. */
    private static function isoDate(string $value): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    /** The stored DATE as the `M/D/YYYY` the cell holds — RcfPage's own rule. */
    private static function american(string $isoDate): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate);

        return $parsed instanceof DateTimeImmutable ? $parsed->format('n/j/Y') : '';
    }
}
