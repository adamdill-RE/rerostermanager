-- 011_rcf_tracking.sql — every Roster Change Form this application produces,
-- kept, with where each line of it has got to.
--
-- SCHEMA, so NOT atomic: MySQL commits implicitly on DDL and a transaction
-- here would report a rollback that did not happen. IF NOT EXISTS throughout,
-- like 001, 008 and 010, so a run that dies half way can start again from the
-- top.
--
--
-- WHY THESE TABLES EXIST
--
-- Phase 9 produced the form and threw it away (spec-v2 §1.4, §11 V2-1): the
-- file was built, sent, unlinked, and one audit row said that somebody made
-- one for some sub-committee with some number of people on it. That was the
-- right first answer, and the first real user of the feature has now asked
-- the question the audit row cannot answer.
--
-- An RCF travels: the Vice Chairman generates it and sends it to the Division
-- Chairman, who numbers it and sends it to Rodeo Houston's membership office,
-- who process it — and the next roster import shows the change. When a
-- member rings to ask why they have not been asked to pay their dues, the
-- question is "was an RCF ever submitted for this person, and where did it
-- stop", and the answer today is somebody sorting through months of emails
-- to find out. So:
--
--   * `rcf` is one generated form: who made it, when, for which
--     sub-committee, exactly as printed. Kept so it can be produced AGAIN,
--     byte for byte, without the roster it was drawn from having to still
--     say what it said then — a form is a request, and the roster changes
--     precisely because the request was granted.
--
--   * `rcf_row` is one line of it, as printed, plus the three things this
--     application did not know before and now tracks: the Division
--     Chairman's RCF NUMBER, the day it went to the Division Chairman, and
--     the day it went to Rosters. Per LINE and not per form, deliberately:
--     the Division Chairman bundles lines from several officers' forms into
--     one numbered form of their own, so the number belongs to the line.
--
-- "Processed by Rodeo Houston" is NOT a column. It is derived, at read time,
-- from `import_change` (010): an addition has landed when the member's number
-- appears as `created` or `returned` after the form was generated, a removal
-- when it appears as `dropped`, a title or team change when that field
-- changed. A stored flag would be somebody's opinion of what the roster
-- says; the roster says it itself.
--
--
-- WHAT THEY ARE NOT
--
-- They are not a way to write the roster. Nothing reads these tables to
-- change a member, and nothing should: a form is a request and the next
-- import is the answer (spec-v2 §11 V2-2). They are ours in the ownership
-- sense of CLAUDE.md — no import writes them, ever — and they are RECORDS:
-- nothing deletes a row here, and the test that reads every write path for
-- a DELETE against the tables that must never lose one names both.


-- ---------------------------------------------------------------------------
-- One generated Roster Change Form
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `rcf` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- The active show year when the form was made. The label is ALSO kept,
    -- below, because it is what was printed on the title bar and a
    -- regenerated form must print the same thing.
    `show_year_id`     INT UNSIGNED NOT NULL,

    -- Who pressed Download. The account, not the member row, because it is
    -- the account that owns the form on /rcfs and the account whose level
    -- decided what the picker offered.
    `generated_by`     INT UNSIGNED NOT NULL,
    `generated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- The three header cells exactly as they were written into the file:
    -- A2's year, G4's "Name, Title", G5's "Division - Team". Text, because
    -- that is what the cells hold, and because the officer named at G4 may
    -- not be the one who generated it (the screen lets somebody fill a form
    -- in for somebody else).
    `year_label`       VARCHAR(32) NOT NULL DEFAULT '',
    `submitter`        VARCHAR(255) NOT NULL DEFAULT '',
    `submitter_number` VARCHAR(32) NOT NULL DEFAULT '',
    `form_date`        DATE NULL,
    `subcommittee`     VARCHAR(255) NOT NULL DEFAULT '',

    -- What the sub-committee label NAMED, for filtering: exactly one of the
    -- two is set. Nullable and unconstrained rather than foreign keys: a
    -- team retired years from now must not make the form that asked for a
    -- change to it unreadable.
    `division_id`      INT UNSIGNED NULL,
    `team_id`          INT UNSIGNED NULL,

    -- How many of the twenty-five rows carried anything. The rows themselves
    -- are below; this is the number the list shows without a join.
    `row_count`        TINYINT UNSIGNED NOT NULL DEFAULT 0,

    -- Downloaded again, how many times and when last. The audit log has each
    -- one; this is the summary the form's own page prints.
    `regenerated_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `last_regenerated_at` DATETIME NULL,

    PRIMARY KEY (`id`),

    -- "My forms, newest first" — the read the screen opens on.
    KEY `ix_rcf_generated_by` (`generated_by`, `id`),
    -- "Every form, newest first", and by year.
    KEY `ix_rcf_show_year` (`show_year_id`, `id`),
    KEY `ix_rcf_team` (`team_id`),
    KEY `ix_rcf_division` (`division_id`),

    CONSTRAINT `fk_rcf_show_year` FOREIGN KEY (`show_year_id`)
        REFERENCES `show_year` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_generated_by` FOREIGN KEY (`generated_by`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- One line of one form, as printed, and where it has got to
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `rcf_row` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rcf_id`           INT UNSIGNED NOT NULL,

    -- The printed row number, 1 to 25. Blank rows are not stored: a form
    -- with three people on it has three rows here, at the positions they
    -- were typed in, and regeneration prints blank between them exactly as
    -- the original did.
    `position`         TINYINT UNSIGNED NOT NULL,

    -- The member the number named, when it named one, so the member card
    -- can list the forms about a person. NULL for an addition typed in from
    -- the wider membership, who has no row here yet — and the number is
    -- kept regardless, because it is what Rodeo Houston will file them
    -- under and what the search box is given. RESTRICT, like every other
    -- foreign key that points at `member`.
    `member_id`        INT UNSIGNED NULL,
    `member_number`    VARCHAR(32) NOT NULL DEFAULT '',
    `member_name`      VARCHAR(160) NOT NULL DEFAULT '',

    -- The row's ten cells, in the form's own vocabulary
    -- (`Rerm\Forms\RosterChangeForm::TYPES`, `REMOVE_REASONS`): the code, not
    -- the description, because the code is what the cell holds. The two
    -- tick boxes are what they are on the paper.
    `type`             VARCHAR(8) NOT NULL DEFAULT '',
    `rookie`           TINYINT(1) NOT NULL DEFAULT 0,
    `new_title`        VARCHAR(160) NOT NULL DEFAULT '',
    `previous_title`   VARCHAR(160) NOT NULL DEFAULT '',
    `wait_list`        TINYINT(1) NOT NULL DEFAULT 0,
    `remove_reason`    VARCHAR(8) NOT NULL DEFAULT '',
    `new_subcommittee` VARCHAR(160) NOT NULL DEFAULT '',
    `sponsor`          VARCHAR(160) NOT NULL DEFAULT '',

    -- WHERE THE LINE HAS GOT TO. Three facts, none of them known to this
    -- application until somebody types them, all of them ours:
    --
    --   serial              the Division Chairman's RCF number — "CHANGE
    --                       FORM #", the box at J1 that says "(DC USE
    --                       ONLY)". Free text: their numbering is theirs.
    --   sent_to_dc_on       the day the officer sent the form to the
    --                       Division Chairman
    --   sent_to_rosters_on  the day the Division Chairman sent their
    --                       numbered form to Rosters (membership)
    --
    -- Dates, not datetimes: these are days somebody is reading off an
    -- email, and a time of day would be invented.
    `serial`             VARCHAR(32) NOT NULL DEFAULT '',
    `sent_to_dc_on`      DATE NULL,
    `sent_to_rosters_on` DATE NULL,

    -- Who last changed any of the three, and when. The audit_log row is the
    -- record, with the values before and after; these two exist so the
    -- screen can say "marked by Erin Delta, 3 days ago" without a join to
    -- a log that may have been filtered.
    `tracked_by`         INT UNSIGNED NULL,
    `tracked_at`         DATETIME NULL,

    PRIMARY KEY (`id`),

    -- One line per printed row per form.
    UNIQUE KEY `ux_rcf_row_position` (`rcf_id`, `position`),
    -- "Every form this member is on" — the member card, and the search.
    KEY `ix_rcf_row_member` (`member_id`, `id`),
    KEY `ix_rcf_row_number` (`member_number`, `id`),
    KEY `ix_rcf_row_tracked_by` (`tracked_by`),

    CONSTRAINT `fk_rcf_row_rcf` FOREIGN KEY (`rcf_id`)
        REFERENCES `rcf` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_row_member` FOREIGN KEY (`member_id`)
        REFERENCES `member` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_row_tracked_by` FOREIGN KEY (`tracked_by`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
