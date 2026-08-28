-- 007_rename_absent_to_dropped.sql — one word, in the two places it is data.
--
-- SCHEMA, so NOT atomic: MySQL commits implicitly on every DDL statement and
-- a half-applied run cannot be rolled back. Every statement below is
-- therefore written to be safe to re-run from the top, which for this
-- migration means each one checks the current state of the column before
-- touching it.
--
--
-- WHY A COLUMN IS BEING RENAMED AT ALL
--
-- "Absent" was the import's word for a member the file did not mention. The
-- owner's word is "dropped", and after Phase 8 that word appears on three
-- screens. Leaving the column called `absent_since_import_id` underneath a UI
-- that says "dropped" is exactly the drift this repository refuses everywhere
-- else — a schema that contradicts the application is worse than an awkward
-- name, because the next person has to hold both in their head.
--
-- The rename is wide but shallow: the column is read as a PREDICATE in
-- exactly one place, `Rerm\Roster\ScopedQuery::visible()`, which builds that
-- string for every read in the application.
--
--
-- DROPPED IS NOT PURGED, AND THE TWO MUST NOT BLUR
--
-- After this migration the two states have names that sound alike, so it is
-- worth writing down which is which:
--
--   dropped   the last complete or team import did not list them.
--             AUTOMATIC, set by the importer, and cleared by the importer the
--             moment they reappear. It means "find out whether this person
--             left", not "this person left".
--
--   purged    an Admin deliberately confirmed removal, typing a word to do
--             it. Cleared only by Restore. It means "we have decided".
--
-- Both are soft. Neither deletes anything, and nothing here changes that.


-- ---------------------------------------------------------------------------
-- 1. member.absent_since_import_id -> member.dropped_since_import_id
-- ---------------------------------------------------------------------------
--
-- Guarded on the column's existence rather than run bare, because this
-- migration is not atomic: a run that dies after this statement leaves the
-- column already renamed, and an unguarded retry would fail on a column that
-- is no longer there, having fixed nothing.
--
-- RENAME COLUMN carries the foreign key and both indexes with it (MySQL 8.0
-- and MariaDB 10.5+ both), so `fk_member_absent_since_import` and
-- `ix_member_absent_since` survive under their old names. Those names are
-- cosmetic and renaming a constraint is a drop and re-add — a great deal of
-- risk against a live 1,954-row table for a string nothing reads. They stay.

SET @has_old := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member'
      AND COLUMN_NAME = 'absent_since_import_id'
);
SET @rename := IF(
    @has_old = 1,
    'ALTER TABLE `member` RENAME COLUMN `absent_since_import_id` TO `dropped_since_import_id`',
    'DO 0'
);
PREPARE rename_member_column FROM @rename;
EXECUTE rename_member_column;
DEALLOCATE PREPARE rename_member_column;


-- ---------------------------------------------------------------------------
-- 1b. import_batch.rows_absent -> import_batch.rows_dropped
-- ---------------------------------------------------------------------------
--
-- The per-batch counter, renamed for the same reason and with the same guard.
-- A plain INT with no key and no constraint on it, so the rename carries
-- nothing with it and cannot fail on a dependency.

SET @has_old := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_batch'
      AND COLUMN_NAME = 'rows_absent'
);
SET @rename := IF(
    @has_old = 1,
    'ALTER TABLE `import_batch` RENAME COLUMN `rows_absent` TO `rows_dropped`',
    'DO 0'
);
PREPARE rename_batch_column FROM @rename;
EXECUTE rename_batch_column;
DEALLOCATE PREPARE rename_batch_column;


-- ---------------------------------------------------------------------------
-- 2. import_staged_row.action: the ENUM value 'absent' -> 'dropped'
-- ---------------------------------------------------------------------------
--
-- An ENUM value cannot be renamed in place while rows hold it. It takes three
-- steps, and the middle one is the only one that touches data:
--
--   a. widen the ENUM to accept BOTH spellings
--   b. rewrite the rows
--   c. narrow it again, dropping the old spelling
--
-- Between (a) and (c) the column accepts a value the application no longer
-- writes, which is exactly what makes the sequence re-runnable: whichever
-- step a failed run stopped at, starting again from (a) is harmless.
--
-- Each step is guarded on what the column currently declares, so a re-run
-- skips whatever is already done rather than repeating it.

SET @action_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_staged_row'
      AND COLUMN_NAME = 'action'
);

-- (a) Widen. Only when 'dropped' is not already accepted.
SET @widen := IF(
    @action_type IS NOT NULL AND LOCATE('dropped', @action_type) = 0,
    "ALTER TABLE `import_staged_row` MODIFY COLUMN `action` ENUM('create', 'update', 'unchanged', 'skip', 'absent', 'dropped') NOT NULL",
    'DO 0'
);
PREPARE widen_action FROM @widen;
EXECUTE widen_action;
DEALLOCATE PREPARE widen_action;

-- (b) Rewrite the rows. Plain UPDATE, safe to repeat: after the first run
--     there is nothing left matching 'absent'.
--
--     No subquery on import_staged_row here, deliberately. MySQL refuses to
--     UPDATE a table while SELECTing from it (error 1093) where MariaDB
--     allows it, and production is MySQL.
UPDATE `import_staged_row` SET `action` = 'dropped' WHERE `action` = 'absent';

-- (c) Narrow. Only when the old spelling is still accepted AND no row holds
--     it — the second half matters because narrowing an ENUM out from under
--     a row that uses it truncates that row's value to '' under a strict
--     sql_mode, silently losing which rows an import would have dropped.
SET @action_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_staged_row'
      AND COLUMN_NAME = 'action'
);
-- The straggler count itself is guarded, because on a re-run 'absent' is no
-- longer a declared value and comparing an ENUM column to one that is not in
-- its list is a warning at best. Building the query only when the value still
-- exists means the comparison is never made against a type that cannot hold
-- it. The count is not merely belt-and-braces: an import running while this
-- migration runs could stage an 'absent' row between (b) and (c).
SET @stragglers := 0;
SET @count_sql := IF(
    @action_type IS NOT NULL AND LOCATE("'absent'", @action_type) > 0,
    "SELECT COUNT(*) INTO @stragglers FROM `import_staged_row` WHERE `action` = 'absent'",
    'DO 0'
);
PREPARE count_stragglers FROM @count_sql;
EXECUTE count_stragglers;
DEALLOCATE PREPARE count_stragglers;
SET @narrow := IF(
    @action_type IS NOT NULL AND LOCATE("'absent'", @action_type) > 0 AND @stragglers = 0,
    "ALTER TABLE `import_staged_row` MODIFY COLUMN `action` ENUM('create', 'update', 'unchanged', 'skip', 'dropped') NOT NULL",
    'DO 0'
);
PREPARE narrow_action FROM @narrow;
EXECUTE narrow_action;
DEALLOCATE PREPARE narrow_action;
