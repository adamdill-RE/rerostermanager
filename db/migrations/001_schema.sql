-- 001_schema.sql — every table in docs/spec-v1.md 5.2.
--
-- Conventions, all of them load-bearing on this host (docs/hosting.md):
--
--   * ENGINE=InnoDB and COLLATE=utf8mb4_unicode_ci are NAMED on every table.
--     MySQL 8.0 defaults to utf8mb4_0900_ai_ci and MariaDB does not; the
--     server default is not ours to rely on. tests/schema_test.php checks it.
--   * Every DATETIME is UTC. Rerm\Database pins the connection's time_zone to
--     '+00:00', so CURRENT_TIMESTAMP defaults record UTC too. Display converts
--     to America/Chicago through a real timezone, never a fixed offset.
--   * Uniqueness keys over generated columns are VIRTUAL, never STORED. Under
--     MySQL a column that a STORED generated column reads cannot carry
--     ON DELETE CASCADE — error 1215, and the table simply will not create.
--     MariaDB accepts the same shape, which is how the sibling application
--     shipped one production could not build.
--   * EVERY foreign key referencing `member` is RESTRICT, never CASCADE, and
--     is written out in full rather than left to default to NO ACTION, so the
--     rule is visible in the file and checkable in information_schema. A purge
--     is a soft delete (member.purged_at); contact history has to outlive the
--     roster (spec 5.5).
--   * CREATE TABLE IF NOT EXISTS throughout. This migration is NOT atomic — it
--     cannot be, because MySQL commits implicitly on DDL — so a run that fails
--     halfway leaves the tables it already made, and the next attempt has to
--     be able to start again from the top.
--
-- Levels are an ENUM declared low to high, so ORDER BY level is rank order.
-- Rank COMPARISON belongs in PHP (Rerm\Auth\Level), never in a SQL string
-- comparison: 'officer' > 'admin' alphabetically and that is not the ordering
-- anybody means.


-- ---------------------------------------------------------------------------
-- Reference data
-- ---------------------------------------------------------------------------

-- Everything metric-, contact- and assignment-related is keyed to a show year.
-- Exactly one row is active, and the database enforces it: is_active_key is a
-- VIRTUAL generated column that is 1 when active and NULL otherwise, and NULL
-- does not collide in a unique index.
CREATE TABLE IF NOT EXISTS `show_year` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `label`         VARCHAR(32) NOT NULL,
    `starts_on`     DATE NULL,
    `ends_on`       DATE NULL,
    -- Open accepts changes; closed is read-only and exportable. Closing
    -- freezes metrics, contacts and assignments — it never deletes them.
    `is_open`       TINYINT(1) NOT NULL DEFAULT 1,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 0,
    `is_active_key` TINYINT UNSIGNED GENERATED ALWAYS AS (IF(`is_active` = 1, 1, NULL)) VIRTUAL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_show_year_label` (`label`),
    UNIQUE KEY `uq_show_year_active` (`is_active_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Four divisions come from the export. The fifth, `(No Division)`, is ours:
-- 72 members arrive with a blank Subcommittee 3 and land here rather than in a
-- NULL, so member.division_id is NOT NULL, no query carries a null branch, and
-- a Senior Officer can be SCOPED to those members instead of them belonging to
-- nobody. is_placeholder marks it, and three rules keep it honest (spec 5.1a):
-- every import re-evaluates membership, the export writes it back as BLANK
-- rather than the literal text, and the no_division warning still fires.
CREATE TABLE IF NOT EXISTS `division` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(128) NOT NULL,
    `is_placeholder` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_division_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Teams are NOT nested inside divisions: seven of the 96 appear under more
-- than one (docs/data-findings.md 4b), so division is a property of the
-- MEMBER. division_id here is the team's modal division and is display only —
-- never read by a scope check.
--
-- `area` is the fourth level in the export's team names, which has no column
-- of its own. It is seeded by prefix heuristic, editable by an Admin, and
-- exists so a 96-team dashboard is legible. It must NEVER appear in
-- Rerm\Auth\Access; tests/access_test.php asserts that.
CREATE TABLE IF NOT EXISTS `team` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(128) NOT NULL,
    `division_id` INT UNSIGNED NULL,
    `area`        VARCHAR(64) NULL,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_team_name` (`name`),
    KEY `ix_team_division` (`division_id`),
    KEY `ix_team_area` (`area`),
    CONSTRAINT `fk_team_division` FOREIGN KEY (`division_id`) REFERENCES `division` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- Imports. Declared before `member` because member rows point back at the
-- batch that last saw them.
-- ---------------------------------------------------------------------------

-- Append-only. Every batch keeps its row counts, its warnings and who ran it,
-- forever, because "why did this member's dues flip back to N" has to stay
-- answerable. dry_run rows are the staging half of the two-step apply (spec
-- 6.3) and are discarded after import.stage_ttl_hours.
CREATE TABLE IF NOT EXISTS `import_batch` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `show_year_id`   INT UNSIGNED NOT NULL,
    `mode`           ENUM('complete', 'update', 'team') NOT NULL,
    -- Team mode only. The Admin chooses the team and the import verifies every
    -- row's Subcommittee 1 against it; a mismatch warns and skips the row
    -- rather than silently retargeting it.
    `team_id`        INT UNSIGNED NULL,
    `filename`       VARCHAR(255) NOT NULL,
    `sha256`         CHAR(64) NOT NULL,
    `uploaded_by`    INT UNSIGNED NULL,
    `rows_read`      INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_created`   INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_updated`   INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_unchanged` INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_absent`    INT UNSIGNED NOT NULL DEFAULT 0,
    `warnings_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `started_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `applied_at`     DATETIME NULL,
    `dry_run`        TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `ix_import_batch_show_year` (`show_year_id`, `started_at`),
    KEY `ix_import_batch_staged` (`dry_run`, `started_at`),
    KEY `ix_import_batch_team` (`team_id`),
    KEY `ix_import_batch_uploader` (`uploaded_by`),
    CONSTRAINT `fk_import_batch_show_year` FOREIGN KEY (`show_year_id`) REFERENCES `show_year` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_import_batch_team` FOREIGN KEY (`team_id`) REFERENCES `team` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- People
-- ---------------------------------------------------------------------------

-- The roster. A member is DATA, not a user: 1,758 of the 1,954 in the sample
-- have no app_user row at all.
--
-- member_number is Customer Number from the export — the natural key, and the
-- only column in the file that is unique across all 1,954 rows. VARCHAR
-- because it is an identifier and never arithmetic: a numeric column loses a
-- leading zero and a float turns 1234567 into 1234567.0.
--
-- Names are NOT unique (1,951 distinct of 1,954) and email is neither unique
-- nor always present (two addresses shared by four people, one member with
-- none). Never key on either.
CREATE TABLE IF NOT EXISTS `member` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_number`         VARCHAR(32) NOT NULL,

    `first_name`            VARCHAR(64) NOT NULL DEFAULT '',
    `last_name`             VARCHAR(64) NOT NULL DEFAULT '',
    -- Populated for roughly half the roster, and it is what every list shows.
    -- Sentinel text (N/A, None, Na) is normalised to '' on import, so that a
    -- member is never greeted as "N/A Smith".
    `preferred_name`        VARCHAR(64) NOT NULL DEFAULT '',
    `full_name`             VARCHAR(160) NOT NULL DEFAULT '',
    `prefix`                VARCHAR(32) NOT NULL DEFAULT '',

    `address`               VARCHAR(160) NOT NULL DEFAULT '',
    `city`                  VARCHAR(96) NOT NULL DEFAULT '',
    `state`                 VARCHAR(32) NOT NULL DEFAULT '',
    `zip`                   VARCHAR(16) NOT NULL DEFAULT '',

    -- Two columns for one number, as in the sibling application: the imported
    -- string is what a human reads, the E.164 form is what tel: and sms: use.
    -- phone_e164 is NULL when the number could not be normalised, which is a
    -- warning rather than a rejection.
    `phone`                 VARCHAR(32) NOT NULL DEFAULT '',
    `phone_e164`            VARCHAR(20) NULL,
    -- Gates the sms: link. 116 of 1,954 are HOME or BUSINESS, and offering
    -- those members a text that silently fails is worse than not offering it.
    `phone_type`            VARCHAR(32) NOT NULL DEFAULT '',

    `email`                 VARCHAR(255) NULL,

    -- HLSR owns both. Every import overwrites them unconditionally, and an
    -- unrecognised title imports as 'member' with a warning naming it — it
    -- never silently becomes an officer.
    `title`                 VARCHAR(96) NOT NULL DEFAULT '',
    `title_level`           ENUM('member', 'officer', 'senior_officer', 'executive_officer', 'admin')
                            NOT NULL DEFAULT 'member',

    -- NOT NULL. A blank Subcommittee 3 lands in the seeded `(No Division)`
    -- row, never in a NULL. team_id IS nullable: a member can exist before
    -- their team row does, and Subcommittee 1 has been blank in the past.
    `division_id`           INT UNSIGNED NOT NULL,
    `team_id`               INT UNSIGNED NULL,

    `legal_name_verified`   TINYINT(1) NOT NULL DEFAULT 0,
    `is_rookie`             TINYINT(1) NOT NULL DEFAULT 0,
    `in_other_committees`   TINYINT(1) NOT NULL DEFAULT 0,
    `badge_pickup_person`   VARCHAR(160) NOT NULL DEFAULT '',

    -- Dead in the observed export — six columns with either one value or no
    -- value at all across 1,954 rows. Imported so a future export that starts
    -- populating one is not silently discarded, surfaced on no screen until it
    -- carries data, and stored as raw text where the value has NEVER been
    -- seen: a DATE column would have to guess a format nobody has observed,
    -- and would fail the whole import the first time it guessed wrong.
    `badge_released`              TINYINT(1) NOT NULL DEFAULT 0,
    `badge_released_date_raw`     VARCHAR(32) NOT NULL DEFAULT '',
    `badge_issue_date_raw`        VARCHAR(32) NOT NULL DEFAULT '',
    `eligible_for_service_history_raw` VARCHAR(32) NOT NULL DEFAULT '',
    `eligibility_updated_by_raw`  VARCHAR(128) NOT NULL DEFAULT '',
    `ltc_applied`                 TINYINT(1) NOT NULL DEFAULT 0,

    `first_imported_at`     DATETIME NULL,
    `last_seen_import_id`   INT UNSIGNED NULL,
    -- Set by a complete or team import that did not see this member. Flagging
    -- only: an Admin confirms the purge as a separate, logged action, and a
    -- member who reappears in a later import is un-flagged automatically.
    `absent_since_import_id` INT UNSIGNED NULL,
    -- A purge is a SOFT delete and this is not negotiable. It hides the member
    -- everywhere; contact_log, assignment and member_metric survive intact.
    `purged_at`             DATETIME NULL,

    -- A row this application created rather than one Rodeo Houston sent. The
    -- seeded master administrator (003) is the only one, and it exists so a
    -- brand-new database has somebody who can sign in and run the first
    -- import. It is not on the committee, so an import must never create,
    -- update, absent or purge it, no roster or roll-up may count it, and the
    -- export must not write it back to Rodeo Houston as though it were theirs.
    --
    -- Without the flag the first complete import would flag the master admin
    -- absent for not appearing in the file, put it on the Flagged for Purge
    -- screen, and invite an Admin to purge the only account that can log in.
    `is_system`             TINYINT(1) NOT NULL DEFAULT 0,

    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_member_number` (`member_number`),
    KEY `ix_member_team` (`team_id`),
    KEY `ix_member_division` (`division_id`),
    KEY `ix_member_title_level` (`title_level`),
    KEY `ix_member_name` (`last_name`, `first_name`),
    KEY `ix_member_email` (`email`),
    -- Every roster read filters purged and absent members out by default, so
    -- the scope predicate and this index are read together.
    KEY `ix_member_visible` (`purged_at`, `absent_since_import_id`, `is_system`, `division_id`, `team_id`),
    KEY `ix_member_last_seen` (`last_seen_import_id`),
    KEY `ix_member_absent_since` (`absent_since_import_id`),
    CONSTRAINT `fk_member_division` FOREIGN KEY (`division_id`) REFERENCES `division` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_member_team` FOREIGN KEY (`team_id`) REFERENCES `team` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_member_last_seen_import` FOREIGN KEY (`last_seen_import_id`) REFERENCES `import_batch` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_member_absent_since_import` FOREIGN KEY (`absent_since_import_id`) REFERENCES `import_batch` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- An account. Created on import for a member whose title maps to Officer or
-- above, or by designation; never for anybody else.
--
--   level          the TITLE-derived level as of the last import. Rewritten
--                  by every import, along with member.title_level.
--   granted_level  an Allowed User designation. DURABLE — no import ever
--                  writes it, which is the entire point of designation.
--
-- effective_level is a VIRTUAL generated column so the rule
-- `granted_level ?? title_level` is written down once, in the schema, and no
-- query re-derives it. VIRTUAL rather than STORED per the convention above,
-- and because a STORED column here would make the base columns unable to
-- carry cascading foreign keys.
CREATE TABLE IF NOT EXISTS `app_user` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id`             INT UNSIGNED NOT NULL,

    `level`                 ENUM('member', 'officer', 'senior_officer', 'executive_officer', 'admin')
                            NOT NULL DEFAULT 'member',
    `granted_level`         ENUM('member', 'officer', 'senior_officer', 'executive_officer', 'admin')
                            NULL DEFAULT NULL,
    `granted_by`            INT UNSIGNED NULL,
    `granted_at`            DATETIME NULL,
    `effective_level`       ENUM('member', 'officer', 'senior_officer', 'executive_officer', 'admin')
                            GENERATED ALWAYS AS (COALESCE(`granted_level`, `level`)) VIRTUAL,

    -- An explicit override, set by an Admin only. Left NULL, a Senior Officer
    -- scopes to their own division and an Officer to their own team, read
    -- from their member row — teams span divisions, so division is a property
    -- of the person and never of the team.
    `scope_division_id`     INT UNSIGNED NULL,
    `scope_team_id`         INT UNSIGNED NULL,

    -- The master admin ships with a hash that cannot verify (003), so the
    -- account exists and is unusable until somebody sets a password
    -- deliberately. This repository is public; no hash belongs in it.
    `password_hash`         VARCHAR(255) NOT NULL,
    `must_change_password`  TINYINT(1) NOT NULL DEFAULT 1,
    `password_changed_at`   DATETIME NULL,

    -- Deactivated, never deleted. A demotion by import sets this to 0 unless a
    -- granted_level holds the account open; a re-promotion reactivates the
    -- same row rather than creating a second one, and the audit trail outlives
    -- the account either way.
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_app_user_member` (`member_id`),
    KEY `ix_app_user_level` (`effective_level`),
    KEY `ix_app_user_granted_by` (`granted_by`),
    KEY `ix_app_user_scope_division` (`scope_division_id`),
    KEY `ix_app_user_scope_team` (`scope_team_id`),
    CONSTRAINT `fk_app_user_member` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_app_user_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_app_user_scope_division` FOREIGN KEY (`scope_division_id`) REFERENCES `division` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_app_user_scope_team` FOREIGN KEY (`scope_team_id`) REFERENCES `team` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- import_batch.uploaded_by closes a genuine cycle: member points at
-- import_batch, import_batch points at app_user, app_user points at member.
-- One of the three has to be added afterwards, and this is the one whose
-- absence during the CREATE costs nothing.
--
-- Guarded, because MySQL 8.0 has no ADD CONSTRAINT IF NOT EXISTS (MariaDB
-- does) and this migration has to survive being re-run. It commits implicitly
-- on every DDL statement, so a run that dies after this line leaves the
-- constraint in place, and an unguarded re-run would fail on a duplicate
-- constraint name having fixed nothing.
SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'import_batch'
      AND CONSTRAINT_NAME = 'fk_import_batch_uploader'
);
SET @add_fk := IF(
    @fk_exists = 0,
    'ALTER TABLE `import_batch` ADD CONSTRAINT `fk_import_batch_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
    'DO 0'
);
PREPARE add_import_batch_uploader FROM @add_fk;
EXECUTE add_import_batch_uploader;
DEALLOCATE PREPARE add_import_batch_uploader;


-- ---------------------------------------------------------------------------
-- Sessions and credentials
-- ---------------------------------------------------------------------------

-- The real session. A PHP session here holds nothing but this row's id,
-- because session.gc_maxlifetime is 1440s on this host and garbage collection
-- belongs to the host, not to us. Keeping the session in a table is also what
-- makes revocation immediate.
--
-- The cookie is `selector.verifier`. The selector is an indexed lookup key and
-- is useless alone; only a SHA-256 of the verifier is stored, compared with
-- hash_equals. Resuming rotates both.
CREATE TABLE IF NOT EXISTS `auth_token` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `selector`      CHAR(32) NOT NULL,
    `verifier_hash` CHAR(64) NOT NULL,
    -- 0 is a browser session; 1 is "keep me signed in", 90 days rolling.
    `is_persistent` TINYINT(1) NOT NULL DEFAULT 0,
    `issued_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at`  DATETIME NULL,
    `expires_at`    DATETIME NOT NULL,
    `revoked_at`    DATETIME NULL,
    `user_agent`    VARCHAR(255) NOT NULL DEFAULT '',
    `ip`            VARCHAR(45) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_auth_token_selector` (`selector`),
    KEY `ix_auth_token_user` (`user_id`, `revoked_at`),
    KEY `ix_auth_token_expiry` (`expires_at`),
    CONSTRAINT `fk_auth_token_user` FOREIGN KEY (`user_id`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Successes are recorded as well as failures, so the audit can tell a typo
-- from an attack. member_number is the string that was typed, not a foreign
-- key: most of what is worth recording here is an attempt on a number that
-- does not exist.
CREATE TABLE IF NOT EXISTS `login_attempt` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip`            VARCHAR(45) NOT NULL DEFAULT '',
    `member_number` VARCHAR(32) NOT NULL DEFAULT '',
    `succeeded`     TINYINT(1) NOT NULL DEFAULT 0,
    `occurred_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `ix_login_attempt_ip` (`ip`, `occurred_at`),
    KEY `ix_login_attempt_member` (`member_number`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Single use, 60 minutes. Same selector/verifier split as auth_token, for the
-- same reason: the emailed half is never what is stored.
CREATE TABLE IF NOT EXISTS `password_reset` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED NOT NULL,
    `selector`      CHAR(32) NOT NULL,
    `verifier_hash` CHAR(64) NOT NULL,
    `expires_at`    DATETIME NOT NULL,
    `used_at`       DATETIME NULL,
    `requested_ip`  VARCHAR(45) NOT NULL DEFAULT '',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_password_reset_selector` (`selector`),
    KEY `ix_password_reset_user` (`user_id`, `expires_at`),
    CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- The work
-- ---------------------------------------------------------------------------

-- Two values for one metric, and the whole application turns on the
-- difference:
--
--   imported_value  what the last import that covered this member said.
--                   HLSR's. Never written by a user.
--   progress        ours. Set by an officer after they made contact.
--
-- Effective status is a pure function of the two plus "contacted this year"
-- (spec 5.4) and is derived in exactly one place in PHP.
--
-- An import that flips imported_value from N to Y RESETS progress to
-- not_started — the thing being chased has happened — and writes the prior
-- value to audit_log with the batch that cleared it. An import that leaves it
-- at N preserves progress untouched: a roster refresh must never erase an
-- officer's work. contact_log is not touched by any of this, ever.
--
-- harassment_training is imported and displayed but is NOT one of the four
-- scored metrics and enters no completion percentage. 1,716 of 1,954 rows are
-- blank, which is 'unknown' and is not the same as N; it renders as
-- "Not reported", never as a failure.
CREATE TABLE IF NOT EXISTS `member_metric` (
    `member_id`         INT UNSIGNED NOT NULL,
    `show_year_id`      INT UNSIGNED NOT NULL,
    `metric`            ENUM('hlsr_dues', 'committee_dues', 'indemnity', 'background_check', 'harassment_training')
                        NOT NULL,

    `imported_value`    ENUM('Y', 'N', 'unknown') NOT NULL DEFAULT 'unknown',
    `imported_at`       DATETIME NULL,
    `imported_batch_id` INT UNSIGNED NULL,

    `progress`          ENUM('not_started', 'in_progress', 'claimed_complete') NOT NULL DEFAULT 'not_started',
    `progress_by`       INT UNSIGNED NULL,
    `progress_at`       DATETIME NULL,
    `progress_note`     VARCHAR(500) NOT NULL DEFAULT '',

    PRIMARY KEY (`member_id`, `show_year_id`, `metric`),
    -- The roll-up on the Committee Dashboard reads exactly this shape.
    KEY `ix_member_metric_rollup` (`show_year_id`, `metric`, `imported_value`, `progress`),
    KEY `ix_member_metric_batch` (`imported_batch_id`),
    KEY `ix_member_metric_progress_by` (`progress_by`),
    CONSTRAINT `fk_member_metric_member` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_member_metric_show_year` FOREIGN KEY (`show_year_id`) REFERENCES `show_year` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_member_metric_batch` FOREIGN KEY (`imported_batch_id`) REFERENCES `import_batch` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_member_metric_progress_by` FOREIGN KEY (`progress_by`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- The record, and the longest-lived thing in this database.
--
-- Never updated, never reset, never rolled, never purged — not by closing a
-- show year, not by opening the next one, not by an import, not by a member
-- purge. It is keyed to a show year so "contacted this year" is answerable,
-- and retained across all of them so that producing a member's history going
-- back years is a query in 2029 rather than a migration (spec 5.5).
--
-- The foreign key to member is RESTRICT, which is what makes that enforceable
-- rather than merely intended: a member row cannot be deleted while history
-- points at it.
CREATE TABLE IF NOT EXISTS `contact_log` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id`    INT UNSIGNED NOT NULL,
    `show_year_id` INT UNSIGNED NOT NULL,
    `contacted_by` INT UNSIGNED NOT NULL,
    `contact_type` ENUM('call', 'text', 'email', 'in_person', 'other') NOT NULL DEFAULT 'call',
    `occurred_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `notes`        VARCHAR(1000) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `ix_contact_log_member` (`member_id`, `occurred_at`),
    KEY `ix_contact_log_year` (`show_year_id`, `occurred_at`),
    KEY `ix_contact_log_officer` (`contacted_by`, `occurred_at`),
    CONSTRAINT `fk_contact_log_member` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_contact_log_show_year` FOREIGN KEY (`show_year_id`) REFERENCES `show_year` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_contact_log_officer` FOREIGN KEY (`contacted_by`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Who is chasing whom, this show year. Superseded via removed_at rather than
-- deleted, so "who was supposed to be calling this member in February" stays
-- answerable.
--
-- is_current is the VIRTUAL generated column that makes the uniqueness key
-- work: 1 while the assignment stands and NULL once it is removed, and NULL
-- does not collide. So one live assignment per (member, officer, year), while
-- any number of removed ones may sit behind it.
--
-- officer_member_id references `member`, not `app_user`: assignments carry
-- forward into the next show year, and an officer whose account was
-- deactivated by a demotion still has to appear on the Assign screen as
-- "officer no longer eligible" with the members they held.
CREATE TABLE IF NOT EXISTS `assignment` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id`         INT UNSIGNED NOT NULL,
    `officer_member_id` INT UNSIGNED NOT NULL,
    `show_year_id`      INT UNSIGNED NOT NULL,
    `assigned_by`       INT UNSIGNED NULL,
    `assigned_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `removed_at`        DATETIME NULL,
    `is_current`        TINYINT UNSIGNED GENERATED ALWAYS AS (IF(`removed_at` IS NULL, 1, NULL)) VIRTUAL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_assignment_current` (`member_id`, `officer_member_id`, `show_year_id`, `is_current`),
    KEY `ix_assignment_officer` (`officer_member_id`, `show_year_id`, `is_current`),
    KEY `ix_assignment_year` (`show_year_id`, `is_current`),
    KEY `ix_assignment_assigned_by` (`assigned_by`),
    CONSTRAINT `fk_assignment_member` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_assignment_officer` FOREIGN KEY (`officer_member_id`) REFERENCES `member` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_assignment_show_year` FOREIGN KEY (`show_year_id`) REFERENCES `show_year` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_assignment_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- The paper trail
-- ---------------------------------------------------------------------------

-- Never fatal, always listed, always attributed to a row number. member_number
-- is the string from the file rather than a foreign key: duplicate_member_number
-- and rows for members that were never created are exactly the cases worth
-- keeping.
CREATE TABLE IF NOT EXISTS `import_warning` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `import_batch_id` INT UNSIGNED NOT NULL,
    `row_number`      INT UNSIGNED NOT NULL DEFAULT 0,
    `member_number`   VARCHAR(32) NULL,
    `kind`            VARCHAR(48) NOT NULL,
    `detail`          VARCHAR(500) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    -- The preview groups by kind with counts: a 72-row no_division list must
    -- not bury a single duplicate_member_number.
    KEY `ix_import_warning_batch_kind` (`import_batch_id`, `kind`),
    CONSTRAINT `fk_import_warning_batch` FOREIGN KEY (`import_batch_id`) REFERENCES `import_batch` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Every grant, revocation, import, purge, password reset and progress reset,
-- with the actor and the time.
--
-- entity/entity_id are polymorphic and carry no foreign key on purpose: an
-- audit row must outlive whatever it describes, and a constraint pointing at a
-- table is the thing that stops that.
--
-- before_json/after_json are JSON. MariaDB implements the type as LONGTEXT
-- with a json_valid CHECK, so write real JSON or nothing — a bare string that
-- passes on MySQL is rejected there.
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_user_id` INT UNSIGNED NULL,
    `action`        VARCHAR(64) NOT NULL,
    `entity`        VARCHAR(64) NOT NULL DEFAULT '',
    `entity_id`     VARCHAR(64) NOT NULL DEFAULT '',
    `before_json`   JSON NULL,
    `after_json`    JSON NULL,
    `occurred_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip`            VARCHAR(45) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `ix_audit_log_time` (`occurred_at`),
    KEY `ix_audit_log_actor` (`actor_user_id`, `occurred_at`),
    KEY `ix_audit_log_entity` (`entity`, `entity_id`),
    KEY `ix_audit_log_action` (`action`, `occurred_at`),
    CONSTRAINT `fk_audit_log_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
