-- 009_contact_history_import.sql — a bulk load of contacts that happened
-- before this application existed.
--
-- SCHEMA, so NOT atomic: MySQL commits implicitly on DDL. `CREATE TABLE IF NOT
-- EXISTS` throughout and the one `ADD COLUMN` guarded on information_schema,
-- so a run that dies halfway starts again from the top.
--
--
-- WHY THIS EXISTS
--
-- Officers were chasing people for months before anybody could log a contact
-- here. Roughly eighty of those calls, texts and conversations are written
-- down in a spreadsheet, and without a way in they are either retyped one
-- screen at a time or lost. Lost is the worse outcome by a distance:
-- `contact_log` is the record this application is built around — "who called
-- this member, and when, and what did they say" — and My Roster Status sorts
-- by it, so a member contacted in October reads as never contacted and goes
-- to the top of somebody's call list in November.
--
-- So: a file in, the same two-step preview-then-apply the roster import uses,
-- and rows that land with their REAL dates rather than today's.
--
--
-- WHY IT DOES NOT REUSE `import_batch` AND `import_staged_row`
--
-- Those tables are the ROSTER import, and 004 says in as many words that
-- nothing this application decides is ever staged in them: not a grant, not a
-- scope, not an assignment, not a contact. That sentence is the ownership
-- boundary of CLAUDE.md written as a data structure, and a contact staged in
-- `import_staged_row.payload` would be the first thing to cross it.
--
-- The boundary is worth more than the two tables cost. A contact is OURS —
-- Rodeo Houston has never heard of it, no roster import may write it, and the
-- one thing an import must never touch is exactly this. Its staging lives
-- here, apart, and the roster importer has nothing to write it from even by
-- mistake.
--
--
-- WHAT IT IS NOT
--
-- It is not an edit path. `contact_log` stays append-only: this migration adds
-- no UPDATE, no DELETE and no `superseded_at`. A history load can be wrong the
-- same way a typed contact can be wrong, and it is corrected the same way — by
-- logging a correcting contact — because "what did somebody believe in
-- October" has to stay answerable in March.


-- ---------------------------------------------------------------------------
-- 1. Where a contact history file waits between the preview and the apply
-- ---------------------------------------------------------------------------

-- One upload. Written with dry_run = 1, read by the preview, consumed by the
-- apply, and discarded after import.stage_ttl_hours if nobody applies it —
-- the same lifecycle as `import_batch`, deliberately, because an Admin who has
-- learned how one import behaves should not have to learn a second.
--
-- `sha256` is what makes a double-apply visible. Eighty contacts loaded twice
-- is eighty duplicate rows in the permanent record, and the file is the only
-- thing that can be recognised before a single row is read.
CREATE TABLE IF NOT EXISTS `contact_import_batch` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- The show year ACTIVE when the file was staged, which is context and not
    -- a destination: each row resolves its OWN year from its own date, below.
    -- A history load spanning a year boundary is the normal case, not an edge
    -- one, and a batch-level year would quietly file October's calls under
    -- whatever happens to be active in March.
    `show_year_id`    INT UNSIGNED NOT NULL,

    -- The team the file is about. Not a filter — a NAME RESOLUTION SCOPE.
    -- Committee-wide there are 1,951 distinct names across 1,954 members and
    -- "Never key on a name" is a rule; inside one team of eighty-odd, an exact
    -- name match is a safe question to ask, and the collisions that remain are
    -- refused rather than guessed (`ambiguous_name`). A file that carries
    -- Customer Numbers does not need this and ignores it.
    `team_id`         INT UNSIGNED NULL,

    -- Every row that does not name its own officer is attributed to this
    -- account. Required: `contact_log.contacted_by` is NOT NULL, and a
    -- contact belonging to nobody is not a record of anything.
    `default_officer_user_id` INT UNSIGNED NOT NULL,

    `filename`        VARCHAR(255) NOT NULL,
    `sha256`          CHAR(64) NOT NULL,
    `uploaded_by`     INT UNSIGNED NULL,

    `rows_read`       INT UNSIGNED NOT NULL DEFAULT 0,
    -- Would insert, on apply.
    `rows_ready`      INT UNSIGNED NOT NULL DEFAULT 0,
    -- Already in `contact_log` — same member, same moment, same type. These
    -- are what makes re-applying a file safe rather than doubling it.
    `rows_duplicate`  INT UNSIGNED NOT NULL DEFAULT 0,
    -- Could not be landed, and the row says why.
    `rows_skipped`    INT UNSIGNED NOT NULL DEFAULT 0,
    -- Actually written. Zero until the apply, and never equal to rows_ready
    -- by assumption — it is counted from the inserts themselves.
    `rows_inserted`   INT UNSIGNED NOT NULL DEFAULT 0,

    -- Rows carrying an `outcome_kind` — everything that did not resolve
    -- silently, whether or not it cost the row. Kept on the batch so the list
    -- of past loads can say "8 rows had something to say about them" without
    -- joining to a hundred staged rows to find out.
    `warnings_count`  INT UNSIGNED NOT NULL DEFAULT 0,

    `started_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `applied_at`      DATETIME NULL,
    `dry_run`         TINYINT(1) NOT NULL DEFAULT 1,

    PRIMARY KEY (`id`),
    KEY `ix_contact_import_batch_staged` (`dry_run`, `started_at`),
    KEY `ix_contact_import_batch_year` (`show_year_id`, `started_at`),
    KEY `ix_contact_import_batch_team` (`team_id`),
    KEY `ix_contact_import_batch_uploader` (`uploaded_by`),
    KEY `ix_contact_import_batch_officer` (`default_officer_user_id`),
    CONSTRAINT `fk_contact_import_batch_year` FOREIGN KEY (`show_year_id`) REFERENCES `show_year` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_contact_import_batch_team` FOREIGN KEY (`team_id`) REFERENCES `team` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_contact_import_batch_officer` FOREIGN KEY (`default_officer_user_id`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_contact_import_batch_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 2. One parsed contact, and what it would do
-- ---------------------------------------------------------------------------

-- Everything the apply needs is RESOLVED HERE, at stage time, and stored: the
-- member, the officer, the show year, the timestamp. The apply reads this
-- table and inserts; it re-resolves nothing.
--
-- That is the point of a two-step import and not a detail of it. The Admin
-- approves a screen that says "Given Surname, 14 October, call" — if the apply
-- resolved names again it could resolve them differently, and the thing
-- written would not be the thing read. It also means the failure modes all
-- happen during the preview, where they are a list on a screen, rather than
-- halfway through writing eighty permanent rows.
--
-- The unresolved strings from the file stay beside the resolved ids, because
-- "Smith, J." matching nobody is only diagnosable if what the file actually
-- said is still here to read.
CREATE TABLE IF NOT EXISTS `contact_import_row` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id`        INT UNSIGNED NOT NULL,

    -- 1-based, counting the header as row 1, so a number here is the number
    -- the Admin sees when they open the file to look.
    `row_number`      INT UNSIGNED NOT NULL,

    --   insert     resolved, and the apply will write it
    --   duplicate  the same contact is already in contact_log
    --   skip       not applied, and skip_kind says why
    `action`          ENUM('insert', 'duplicate', 'skip') NOT NULL,

    -- Which of the reasons in Rerm\Import\ContactImporter::KINDS, and it is
    -- NOT only a skip reason: two of them annotate a row that landed anyway
    -- (an unrecognised type word, a date no show year covers). Empty for a row
    -- that resolved with nothing worth saying about it.
    `outcome_kind`       VARCHAR(48) NOT NULL DEFAULT '',
    `detail`          VARCHAR(500) NOT NULL DEFAULT '',

    -- What the file said, verbatim and truncated, for every column that had
    -- to be resolved. Kept whatever the outcome.
    `raw_member`      VARCHAR(255) NOT NULL DEFAULT '',
    `raw_officer`     VARCHAR(255) NOT NULL DEFAULT '',
    `raw_date`        VARCHAR(64) NOT NULL DEFAULT '',
    `raw_type`        VARCHAR(64) NOT NULL DEFAULT '',

    -- What it resolved to. NULL on a skip, which is what a skip means.
    `member_id`       INT UNSIGNED NULL,
    `contacted_by`    INT UNSIGNED NULL,
    `show_year_id`    INT UNSIGNED NULL,
    `contact_type`    ENUM('call', 'text', 'email', 'in_person', 'other') NOT NULL DEFAULT 'call',
    `occurred_at`     DATETIME NULL,
    -- VARCHAR(1000) exactly like contact_log.notes: staging a note the
    -- destination cannot hold is a truncation discovered on apply.
    `notes`           VARCHAR(1000) NOT NULL DEFAULT '',

    PRIMARY KEY (`id`),
    -- The preview reads a batch in file order, and counts by action.
    KEY `ix_contact_import_row_batch` (`batch_id`, `action`, `row_number`),
    KEY `ix_contact_import_row_member` (`member_id`),
    KEY `ix_contact_import_row_officer` (`contacted_by`),
    KEY `ix_contact_import_row_year` (`show_year_id`),
    CONSTRAINT `fk_contact_import_row_batch` FOREIGN KEY (`batch_id`) REFERENCES `contact_import_batch` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_contact_import_row_member` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_contact_import_row_officer` FOREIGN KEY (`contacted_by`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_contact_import_row_year` FOREIGN KEY (`show_year_id`) REFERENCES `show_year` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 3. contact_log learns where a row came from
-- ---------------------------------------------------------------------------
--
-- NULL for every row logged on a screen by a person, which is every row that
-- exists today and every row the application writes from here on. Set only by
-- a history load.
--
-- It earns the column twice. On screen, a member's history can say that a
-- March entry was typed by their captain and an October one was loaded from a
-- spreadsheet in December — the same distinction the application already
-- makes between an imported metric value and a progress note somebody set,
-- and for the same reason: a reader is entitled to know how sure to be.
-- Behind the screen it is the only way to ask "what did that load actually
-- write", which is the first question anybody asks when eighty rows appear at
-- once and one of them looks wrong.
--
-- It is NOT a delete handle. There is no code path anywhere that removes rows
-- by batch, and adding one would break the rule this table exists to keep
-- (CLAUDE.md; docs/spec-v1.md 5.5). RESTRICT on the foreign key says the same
-- thing from the other direction: a batch cannot be deleted while contacts
-- cite it.

SET @has_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'contact_log'
      AND COLUMN_NAME = 'contact_import_batch_id'
);
SET @add_column := IF(
    @has_column = 0,
    'ALTER TABLE `contact_log` ADD COLUMN `contact_import_batch_id` INT UNSIGNED NULL AFTER `notes`',
    'DO 0'
);
PREPARE add_contact_log_batch FROM @add_column;
EXECUTE add_contact_log_batch;
DEALLOCATE PREPARE add_contact_log_batch;


SET @has_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'contact_log'
      AND INDEX_NAME = 'ix_contact_log_import_batch'
);
SET @add_index := IF(
    @has_index = 0,
    'ALTER TABLE `contact_log` ADD KEY `ix_contact_log_import_batch` (`contact_import_batch_id`)',
    'DO 0'
);
PREPARE add_contact_log_index FROM @add_index;
EXECUTE add_contact_log_index;
DEALLOCATE PREPARE add_contact_log_index;


-- MySQL has no IF NOT EXISTS for ADD CONSTRAINT, so it is guarded the way
-- 001 guards its own — by asking information_schema first.
SET @has_fk := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'contact_log'
      AND CONSTRAINT_NAME = 'fk_contact_log_import_batch'
);
SET @add_fk := IF(
    @has_fk = 0,
    'ALTER TABLE `contact_log` ADD CONSTRAINT `fk_contact_log_import_batch` FOREIGN KEY (`contact_import_batch_id`) REFERENCES `contact_import_batch` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
    'DO 0'
);
PREPARE add_contact_log_fk FROM @add_fk;
EXECUTE add_contact_log_fk;
DEALLOCATE PREPARE add_contact_log_fk;
