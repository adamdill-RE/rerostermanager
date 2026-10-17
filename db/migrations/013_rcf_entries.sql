-- ---------------------------------------------------------------------------
-- 013: RCF entries and the approval flow (Phase 14, spec-v2 §15).
--
-- A Vice Chairman asks for one change at a time — an ENTRY, one line of a
-- future Roster Change Form — and it goes up a chain of approvers an Admin
-- has drawn before the account at the top of the chain puts everything it
-- approved onto one or more consolidated forms, kept through the same
-- `rcf` / `rcf_row` as every other form.
--
-- Schema, so NOT atomic: MySQL commits implicitly on DDL. Every statement
-- is guarded so a re-run after a half-applied attempt is a no-op.
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- Who approves whom. One row per account; CURRENT STATE rather than a record
-- (the history is audit_log's `set_approver`), like app_user_team.
--
-- Three states, spelled by two columns:
--   approver_user_id set          their entries wait on that account
--   is_top = 1                    nobody is above them; their entries are
--                                 approved the moment they are submitted
--   neither                       not set: their entries are REFUSED with a
--                                 sentence, never routed to a default
-- Nothing deletes a row; "not set" is written as both values empty.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rcf_approver` (
    `app_user_id`      INT UNSIGNED NOT NULL,
    `approver_user_id` INT UNSIGNED NULL,
    `is_top`           TINYINT(1) NOT NULL DEFAULT 0,
    `set_by`           INT UNSIGNED NOT NULL,
    `set_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`app_user_id`),
    KEY `ix_rcf_approver_approver` (`approver_user_id`),
    KEY `ix_rcf_approver_set_by` (`set_by`),

    CONSTRAINT `fk_rcf_approver_user` FOREIGN KEY (`app_user_id`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_approver_approver` FOREIGN KEY (`approver_user_id`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_approver_set_by` FOREIGN KEY (`set_by`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- One requested change. The ten cells are rcf_row's, column for column, so
-- an entry becomes a line without translation; the sub-committee is the
-- heading the line will print under, because a removal or a title change
-- does not say which team the member is on — the heading does.
--
-- A RECORD: nothing deletes a row (tests/admin_test.php's protected list).
-- A returned or withdrawn entry stays as what was asked and what was said.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rcf_entry` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `show_year_id`        INT UNSIGNED NOT NULL,
    `requested_by`        INT UNSIGNED NOT NULL,
    `requested_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- The sub-committee the line is about: exactly one of the two ids, and
    -- the label as the heading will print it. Nullable and unconstrained
    -- like rcf's, for the same reason: a team retired later must not make
    -- the request unreadable.
    `division_id`         INT UNSIGNED NULL,
    `team_id`             INT UNSIGNED NULL,
    `subcommittee`        VARCHAR(255) NOT NULL DEFAULT '',

    -- The ten cells (Rerm\Forms\RosterChangeForm::TYPES, REMOVE_REASONS).
    `member_id`           INT UNSIGNED NULL,
    `member_number`       VARCHAR(32) NOT NULL DEFAULT '',
    `member_name`         VARCHAR(160) NOT NULL DEFAULT '',
    `type`                VARCHAR(8) NOT NULL DEFAULT '',
    `rookie`              TINYINT(1) NOT NULL DEFAULT 0,
    `new_title`           VARCHAR(160) NOT NULL DEFAULT '',
    `previous_title`      VARCHAR(160) NOT NULL DEFAULT '',
    `wait_list`           TINYINT(1) NOT NULL DEFAULT 0,
    `remove_reason`       VARCHAR(8) NOT NULL DEFAULT '',
    `new_subcommittee`    VARCHAR(160) NOT NULL DEFAULT '',
    `sponsor`             VARCHAR(160) NOT NULL DEFAULT '',

    --   pending     waiting on current_approver_id
    --   approved    the chain is exhausted; final_approver_id approved last
    --   returned    an approver sent it back, with return_note
    --   withdrawn   the requester took it back while it was pending
    --   generated   on a consolidated form: rcf_id and rcf_row_id say which
    `status`              ENUM('pending', 'approved', 'returned', 'withdrawn', 'generated')
                          NOT NULL DEFAULT 'pending',
    `current_approver_id` INT UNSIGNED NULL,
    `final_approver_id`   INT UNSIGNED NULL,
    `approved_at`         DATETIME NULL,
    `returned_by`         INT UNSIGNED NULL,
    `returned_at`         DATETIME NULL,
    `return_note`         VARCHAR(500) NOT NULL DEFAULT '',
    `withdrawn_at`        DATETIME NULL,
    `rcf_id`              INT UNSIGNED NULL,
    `rcf_row_id`          INT UNSIGNED NULL,
    `generated_at`        DATETIME NULL,

    PRIMARY KEY (`id`),
    -- "My entries", "waiting on me", "ready for me to generate": the three
    -- lists the screens open on, each newest first.
    KEY `ix_rcf_entry_requester` (`requested_by`, `status`, `id`),
    KEY `ix_rcf_entry_waiting` (`current_approver_id`, `status`, `id`),
    KEY `ix_rcf_entry_final` (`final_approver_id`, `status`, `id`),
    KEY `ix_rcf_entry_status` (`status`, `id`),
    KEY `ix_rcf_entry_member` (`member_id`),
    KEY `ix_rcf_entry_rcf` (`rcf_id`),
    KEY `ix_rcf_entry_row` (`rcf_row_id`),
    KEY `ix_rcf_entry_year` (`show_year_id`),
    KEY `ix_rcf_entry_returned_by` (`returned_by`),

    CONSTRAINT `fk_rcf_entry_year` FOREIGN KEY (`show_year_id`)
        REFERENCES `show_year` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_entry_requester` FOREIGN KEY (`requested_by`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_entry_member` FOREIGN KEY (`member_id`)
        REFERENCES `member` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_entry_current` FOREIGN KEY (`current_approver_id`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_entry_final` FOREIGN KEY (`final_approver_id`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_entry_returned_by` FOREIGN KEY (`returned_by`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_entry_rcf` FOREIGN KEY (`rcf_id`)
        REFERENCES `rcf` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_entry_row` FOREIGN KEY (`rcf_row_id`)
        REFERENCES `rcf_row` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Every hand an entry passed through, in order: who did what to it, when,
-- and what they said. The audit log has the same facts with before and
-- after; this is the shape a screen reads to say "approved by Bob 2 Oct,
-- waiting on Carl" without parsing JSON. Append-only, a record.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rcf_entry_step` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entry_id`      INT UNSIGNED NOT NULL,
    `step`          TINYINT UNSIGNED NOT NULL,
    `actor_user_id` INT UNSIGNED NOT NULL,
    `action`        ENUM('submitted', 'approved', 'returned', 'withdrawn', 'generated') NOT NULL,
    `note`          VARCHAR(500) NOT NULL DEFAULT '',
    `acted_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_rcf_entry_step` (`entry_id`, `step`),
    KEY `ix_rcf_entry_step_actor` (`actor_user_id`, `id`),

    CONSTRAINT `fk_rcf_entry_step_entry` FOREIGN KEY (`entry_id`)
        REFERENCES `rcf_entry` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_entry_step_actor` FOREIGN KEY (`actor_user_id`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- A consolidated form is a third source of kept form (012 added the ENUM
-- with two). Guarded on information_schema so a re-run is a no-op: MODIFY
-- COLUMN with the same definition is harmless, but it is also a rebuild of
-- the table on some engines, and a no-op costs nothing.
-- ---------------------------------------------------------------------------
SET @source_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf'
      AND COLUMN_NAME = 'source'
);
SET @widen_source := IF(
    @source_type IS NOT NULL AND LOCATE('consolidated', @source_type) = 0,
    'ALTER TABLE `rcf` MODIFY COLUMN `source` ENUM(''generated'', ''uploaded'', ''consolidated'') NOT NULL DEFAULT ''generated''',
    'DO 0'
);
PREPARE widen_rcf_source FROM @widen_source;
EXECUTE widen_rcf_source;
DEALLOCATE PREPARE widen_rcf_source;
