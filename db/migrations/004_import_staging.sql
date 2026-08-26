-- 004_import_staging.sql — where a roster waits between the preview and the
-- apply (spec 6.3).
--
-- The two-step import is the single most important safety property of this
-- application: one screen can rewrite 1,954 rows, so the file is parsed into
-- staging first, the Admin reads a diff, and only a second explicit POST
-- touches `member`. That needs somewhere to put 1,954 parsed rows in between,
-- and this is it.
--
-- Not atomic, and it cannot be: MySQL commits implicitly on DDL, so a
-- transaction around a CREATE TABLE would report a rollback that did not
-- happen. IF NOT EXISTS throughout so a run that dies halfway can start again
-- from the top — the same shape as 001.


-- ---------------------------------------------------------------------------
-- One parsed row, and what it would do
-- ---------------------------------------------------------------------------

-- Written with dry_run = 1, read by the preview, consumed by the apply, and
-- discarded after import.stage_ttl_hours (24) if nobody applies it.
--
-- `payload` holds ONLY what Rodeo Houston owns (spec 6.6). Nothing this
-- application decides — a grant, a scope override, a password, a contact, an
-- assignment, a progress status, a team's area — is ever staged, so the apply
-- has nothing to write them from even by mistake. That is the ownership
-- boundary expressed as a data structure rather than as a rule somebody has to
-- remember while editing an UPDATE statement.
--
-- Team and division travel as NAMES, not ids. The rows they resolve to may not
-- exist yet — 96 teams are new on a first import — and a dry run that created
-- reference data for an import somebody then abandoned would leave 96 empty
-- teams on the dashboard. The apply resolves them; the preview reports what it
-- would create through the `new_team` warning.
CREATE TABLE IF NOT EXISTS `import_staged_row` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `import_batch_id` INT UNSIGNED NOT NULL,

    -- 1-based, counting the header as row 1, so a number here is the number
    -- the Admin sees when they open the file to look.
    `row_number`      INT UNSIGNED NOT NULL,
    `member_number`   VARCHAR(32) NOT NULL DEFAULT '',

    --   create     no member with this number yet
    --   update     exists, and at least one HLSR-owned value differs
    --   unchanged  exists, and nothing differs. A second import of the same
    --              file is ~1,954 of these and zero of everything else.
    --   skip       not applied, and the warning beside it says why: a
    --              duplicate number, a wrong team in team mode, a blank key,
    --              or a collision with an application account
    --   absent     NOT a file row. A member the file did not mention, staged
    --              so the preview can list who would be flagged and the apply
    --              flags exactly that list rather than recomputing it
    `action`          ENUM('create', 'update', 'unchanged', 'skip', 'absent') NOT NULL,

    -- The member this row matched, when it matched one. NULL for a create and
    -- for a skip. RESTRICT like every other foreign key pointing at `member`.
    `member_id`       INT UNSIGNED NULL,

    `payload`         JSON NULL,
    -- field => [before, after], for the rows the preview shows in full. Only
    -- the fields that actually differ, so an unchanged row carries nothing and
    -- an update carries three or four entries rather than forty.
    `changes`         JSON NULL,

    PRIMARY KEY (`id`),
    -- The preview reads by batch and action; the apply reads by batch, action
    -- and id order. One index serves both.
    KEY `ix_staged_row_batch_action` (`import_batch_id`, `action`, `id`),
    KEY `ix_staged_row_member` (`member_id`),
    KEY `ix_staged_row_number` (`import_batch_id`, `member_number`),
    CONSTRAINT `fk_staged_row_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batch` (`id`)
        ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT `fk_staged_row_member` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- CASCADE on the batch above is deliberate and is the one place in this schema
-- it appears. A staged row is not a record of anything — it is a parse of a
-- file, thrown away 24 hours later — and the alternative is a discard path
-- that has to delete children in the right order and will one day miss one,
-- leaving orphans nothing reads and nobody can find. The rule it does not
-- break is the one that matters: every foreign key referencing `member` is
-- RESTRICT, including the one above, and tests/schema_test.php checks it.


-- ---------------------------------------------------------------------------
-- What the whole file would do, in one row
-- ---------------------------------------------------------------------------

-- The preview summarises metric flips — "412 members would move to Committee
-- Dues = Y" — and reading that back out of 1,954 JSON blobs to render one
-- paragraph is work done on every page view for an answer that was already
-- known when the file was parsed. It is computed once, at stage time, and kept
-- here.
--
-- Guarded rather than ADD COLUMN IF NOT EXISTS: MariaDB has that syntax and
-- MySQL 8.0 does not, and production is MySQL. Same shape as the guarded
-- constraint at the end of 001, and for the same reason — DDL commits
-- implicitly, so a re-run has to find its own work already done and carry on.
SET @column_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_batch'
      AND COLUMN_NAME = 'summary_json'
);
SET @add_column := IF(
    @column_exists = 0,
    'ALTER TABLE `import_batch` ADD COLUMN `summary_json` JSON NULL AFTER `warnings_count`',
    'DO 0'
);
PREPARE add_import_batch_summary FROM @add_column;
EXECUTE add_import_batch_summary;
DEALLOCATE PREPARE add_import_batch_summary;
