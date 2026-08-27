-- 005_import_failure.sql — what an import that died half way leaves behind.
--
-- The apply runs one transaction per import.batch_rows, which is what keeps a
-- 1,954-row roster inside a 30-second ceiling against a database on another
-- machine. The cost of that shape is that a failure in the ninth chunk rolls
-- back the ninth chunk and nothing else: the roster is genuinely, partly
-- updated, and no transaction can be wound back to undo it.
--
-- Before these two columns existed, that left the worst state this
-- application can be in. The batch still read as unapplied, so the screen
-- still offered an Apply button; pressing it re-ran the creates that had
-- already succeeded and produced a raw duplicate-key error. Nothing anywhere
-- said the roster was half-written, and nothing said what to do about it.
--
-- What to do about it is simple and it is the ONLY safe move: upload the file
-- again. A fresh parse diffs against the roster as it now stands — including
-- the rows the failed run managed to write — so the new preview shows exactly
-- the remainder. What was missing was a place to record that the old batch is
-- spent, and a sentence telling the Admin that.
--
-- Not atomic: MySQL commits implicitly on DDL, so a transaction here would
-- report a rollback that did not happen.


-- Guarded rather than ADD COLUMN IF NOT EXISTS, which MariaDB has and MySQL
-- 8.0 does not — and production is MySQL. The same shape as the guarded
-- constraint at the end of 001 and the guarded column in 004: DDL commits as
-- it goes, so a re-run has to find its own work already done and carry on.
SET @failed_at_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_batch'
      AND COLUMN_NAME = 'failed_at'
);
SET @add_failed_at := IF(
    @failed_at_exists = 0,
    'ALTER TABLE `import_batch` ADD COLUMN `failed_at` DATETIME NULL AFTER `applied_at`',
    'DO 0'
);
PREPARE add_import_batch_failed_at FROM @add_failed_at;
EXECUTE add_import_batch_failed_at;
DEALLOCATE PREPARE add_import_batch_failed_at;


-- The driver's own message, kept verbatim and truncated to the column. It is
-- rendered to an Admin and it is the only description of the failure that
-- survives the request, so a paraphrase would be worse than the raw text.
SET @reason_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_batch'
      AND COLUMN_NAME = 'failure_reason'
);
SET @add_reason := IF(
    @reason_exists = 0,
    'ALTER TABLE `import_batch` ADD COLUMN `failure_reason` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `failed_at`',
    'DO 0'
);
PREPARE add_import_batch_failure_reason FROM @add_reason;
EXECUTE add_import_batch_failure_reason;
DEALLOCATE PREPARE add_import_batch_failure_reason;


-- An index on it, because two things sweep this table and both now have to
-- ask the question: the 24-hour discard of stale previews, and the list of
-- batches waiting to be applied. A failed batch belongs in neither.
--
-- It belongs in neither for a reason worth writing down: a failed batch WROTE
-- MEMBERS, so `member`.`last_seen_import_id` points at it, and every foreign
-- key referencing a member-bearing row here is RESTRICT. Deleting one would
-- not quietly orphan anything — the database would refuse outright, and the
-- discard sweep would start throwing on a page an Admin visits to do
-- something else entirely. The index makes excluding it cheap; RESTRICT is
-- what makes forgetting to exclude it loud rather than silent.
SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_batch'
      AND INDEX_NAME = 'ix_import_batch_failed'
);
SET @add_index := IF(
    @index_exists = 0,
    'ALTER TABLE `import_batch` ADD KEY `ix_import_batch_failed` (`failed_at`, `applied_at`, `started_at`)',
    'DO 0'
);
PREPARE add_import_batch_failed_index FROM @add_index;
EXECUTE add_import_batch_failed_index;
DEALLOCATE PREPARE add_import_batch_failed_index;
