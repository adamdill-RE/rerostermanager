-- ---------------------------------------------------------------------------
-- 014: a consolidated Roster Change Form can be VOIDED (Phase 14.2,
-- spec-v2 §17).
--
-- Phase 14.1 gave an Admin a way to walk the approval flow alone, and the
-- first thing walking it produces is a consolidated form that was never
-- meant for Rodeo Houston: a test. Until now it sat on Track RCFs, on the
-- requester's list, on every approver's "decided by you" and on the member
-- card for ever, because nothing deletes a kept form. This migration adds
-- the honest alternative: the form and the entries it was cut from are
-- STAMPED voided, and every read of the record filters the stamp, so the
-- form is gone from view for everybody in the chain while the rows — and
-- the audit row that says who voided it and why — stay.
--
-- Schema, so NOT atomic: MySQL commits implicitly on DDL. Every statement
-- is guarded on information_schema so a re-run after a half-applied
-- attempt is a no-op, the pattern 012 and 013 set.
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- 1. rcf: when it was voided, by whom, and the note that says why
-- ---------------------------------------------------------------------------
SET @has_voided_at := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf'
      AND COLUMN_NAME = 'voided_at'
);
SET @add_voided_at := IF(
    @has_voided_at = 0,
    'ALTER TABLE `rcf` ADD COLUMN `voided_at` DATETIME NULL AFTER `last_regenerated_at`',
    'DO 0'
);
PREPARE add_rcf_voided_at FROM @add_voided_at;
EXECUTE add_rcf_voided_at;
DEALLOCATE PREPARE add_rcf_voided_at;


SET @has_voided_by := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf'
      AND COLUMN_NAME = 'voided_by'
);
SET @add_voided_by := IF(
    @has_voided_by = 0,
    'ALTER TABLE `rcf` ADD COLUMN `voided_by` INT UNSIGNED NULL AFTER `voided_at`',
    'DO 0'
);
PREPARE add_rcf_voided_by FROM @add_voided_by;
EXECUTE add_rcf_voided_by;
DEALLOCATE PREPARE add_rcf_voided_by;


SET @has_void_note := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf'
      AND COLUMN_NAME = 'void_note'
);
SET @add_void_note := IF(
    @has_void_note = 0,
    'ALTER TABLE `rcf` ADD COLUMN `void_note` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `voided_by`',
    'DO 0'
);
PREPARE add_rcf_void_note FROM @add_void_note;
EXECUTE add_rcf_void_note;
DEALLOCATE PREPARE add_rcf_void_note;


-- Who voided it is an account, and RESTRICT like every other key that
-- points at app_user: the record outlives the account.
SET @has_voided_by_fk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf'
      AND CONSTRAINT_NAME = 'fk_rcf_voided_by'
);
SET @add_voided_by_fk := IF(
    @has_voided_by_fk = 0,
    'ALTER TABLE `rcf` ADD CONSTRAINT `fk_rcf_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
    'DO 0'
);
PREPARE add_rcf_voided_by_fk FROM @add_voided_by_fk;
EXECUTE add_rcf_voided_by_fk;
DEALLOCATE PREPARE add_rcf_voided_by_fk;


-- ---------------------------------------------------------------------------
-- 2. rcf_entry: the same stamp on every entry the voided form was cut from.
--
-- Denormalised on purpose. Every list of entries filters it, and a filter
-- that needed a join to `rcf` on every one of the seven lists is a filter
-- one of them would eventually forget. The entry keeps status 'generated'
-- and its rcf_id — what happened to it is still true — and the stamp says
-- it is no longer anybody's to see.
-- ---------------------------------------------------------------------------
SET @has_entry_voided := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf_entry'
      AND COLUMN_NAME = 'voided_at'
);
SET @add_entry_voided := IF(
    @has_entry_voided = 0,
    'ALTER TABLE `rcf_entry` ADD COLUMN `voided_at` DATETIME NULL AFTER `generated_at`',
    'DO 0'
);
PREPARE add_rcf_entry_voided FROM @add_entry_voided;
EXECUTE add_rcf_entry_voided;
DEALLOCATE PREPARE add_rcf_entry_voided;


-- ---------------------------------------------------------------------------
-- 3. rcf_entry_step: the void is one more hand the entry passed through,
--    with the note, so the record of the entry says it in order.
-- ---------------------------------------------------------------------------
SET @step_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf_entry_step'
      AND COLUMN_NAME = 'action'
);
SET @widen_step := IF(
    @step_type IS NOT NULL AND LOCATE('voided', @step_type) = 0,
    'ALTER TABLE `rcf_entry_step` MODIFY COLUMN `action` ENUM(''submitted'', ''approved'', ''returned'', ''withdrawn'', ''generated'', ''voided'') NOT NULL',
    'DO 0'
);
PREPARE widen_rcf_entry_step FROM @widen_step;
EXECUTE widen_rcf_entry_step;
DEALLOCATE PREPARE widen_rcf_entry_step;
