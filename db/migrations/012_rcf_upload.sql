-- 012_rcf_upload.sql — Roster Change Forms that arrived by email, uploaded
-- into the same record as the ones this application produced.
--
-- SCHEMA, so NOT atomic: MySQL commits implicitly on DDL and a transaction
-- here would report a rollback that did not happen. `CREATE TABLE IF NOT
-- EXISTS` throughout and every `ADD COLUMN` / `ADD KEY` guarded on
-- information_schema, like 009, so a run that dies half way starts again
-- from the top.
--
--
-- WHY THIS EXISTS
--
-- 011 keeps every Roster Change Form THIS application makes (spec-v2 §12).
-- Most of the forms in circulation were not made here: a Vice Chairman fills
-- in the Excel template by hand and emails it, the Division Chairman numbers
-- it and forwards it, and the Admin is copied on all of it. For those forms
-- "was an RCF ever submitted for this member, and did Rodeo Houston process
-- it" is still an email search. Phase 13 (spec-v2 §14) uploads them, reads
-- them, and keeps them in `rcf` / `rcf_row` beside the generated ones, so
-- every screen that reads the record — Track RCFs, the member card, Look Up
-- Members — answers for both without knowing the difference, except where
-- it should say so.
--
-- Three columns on `rcf` say so. Two staging tables hold what was read
-- between the upload and the keep, because a kept form is a RECORD (nothing
-- deletes a row of `rcf`) and the only chance to notice a misread column or
-- a file that is not a form is BEFORE it is written — the same two steps
-- the roster import (004) and the contact history load (009) take, for the
-- same reason.
--
--
-- WHAT THE STAGING TABLES ARE NOT
--
-- They are not records. Nothing in them has ever been in `rcf`; a staged
-- batch is a preview, discarded on request or swept after
-- import.stage_ttl_hours if nobody keeps it, exactly like `import_batch`
-- with dry_run = 1 and `contact_import_batch`. A kept batch stays — its
-- rows say which file became which form — but it is `rcf` that is the
-- record of the form, and tests/admin_test.php's protected list names `rcf`
-- and `rcf_row`, not these.
--
-- The uploaded FILE is kept nowhere. It is read from PHP's own temporary
-- upload and never copied under var/: a filled-in form names members and
-- carries their member numbers. The sha256 on the staged file answers "have
-- we kept this one already" without keeping a byte of it.


-- ---------------------------------------------------------------------------
-- 1. rcf learns where a form came from
-- ---------------------------------------------------------------------------

-- `source`: generated here, or uploaded from a file. Every row that exists
-- today was generated, which is what the default says. Read by the screens
-- ("made here by" / "uploaded by") and by RcfTracking::landed(), which
-- measures an uploaded form from its OWN date rather than from the moment
-- it was kept — a form dated in February and uploaded in October was
-- fulfilled by March's import.
SET @has_source := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf'
      AND COLUMN_NAME = 'source'
);
SET @add_source := IF(
    @has_source = 0,
    'ALTER TABLE `rcf` ADD COLUMN `source` ENUM(''generated'', ''uploaded'') NOT NULL DEFAULT ''generated'' AFTER `generated_at`',
    'DO 0'
);
PREPARE add_rcf_source FROM @add_source;
EXECUTE add_rcf_source;
DEALLOCATE PREPARE add_rcf_source;


-- The file an uploaded form was read from — its name as the browser sent
-- it (basename only, bounded), which is how the Admin recognises "the one
-- from Tuesday's email" — and its sha256, which is how a second upload of
-- the same file is recognised and refused. Empty and NULL for a generated
-- form, which came from no file.
SET @has_filename := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf'
      AND COLUMN_NAME = 'upload_filename'
);
SET @add_filename := IF(
    @has_filename = 0,
    'ALTER TABLE `rcf` ADD COLUMN `upload_filename` VARCHAR(255) NOT NULL DEFAULT '''' AFTER `source`',
    'DO 0'
);
PREPARE add_rcf_filename FROM @add_filename;
EXECUTE add_rcf_filename;
DEALLOCATE PREPARE add_rcf_filename;


SET @has_sha := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf'
      AND COLUMN_NAME = 'upload_sha256'
);
SET @add_sha := IF(
    @has_sha = 0,
    'ALTER TABLE `rcf` ADD COLUMN `upload_sha256` CHAR(64) NULL AFTER `upload_filename`',
    'DO 0'
);
PREPARE add_rcf_sha FROM @add_sha;
EXECUTE add_rcf_sha;
DEALLOCATE PREPARE add_rcf_sha;


-- "Have we kept this exact file before" is asked once per uploaded file,
-- before anything is staged, so it is indexed.
SET @has_sha_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rcf'
      AND INDEX_NAME = 'ix_rcf_upload_sha256'
);
SET @add_sha_index := IF(
    @has_sha_index = 0,
    'ALTER TABLE `rcf` ADD KEY `ix_rcf_upload_sha256` (`upload_sha256`)',
    'DO 0'
);
PREPARE add_rcf_sha_index FROM @add_sha_index;
EXECUTE add_rcf_sha_index;
DEALLOCATE PREPARE add_rcf_sha_index;


-- ---------------------------------------------------------------------------
-- 2. One upload — several files, read together, kept together
-- ---------------------------------------------------------------------------

-- One batch per press of "Read the files". It is staged with applied_at
-- NULL, read by the preview, and either kept — applied_at set, the files
-- below saying which form each became — or discarded, which deletes it and
-- its files. Never both, and a kept batch is never deleted: it is how the
-- Admin later answers "which upload did this form come from".
CREATE TABLE IF NOT EXISTS `rcf_upload_batch` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Who pressed the button. The account, not the member row, because it
    -- is the account that will own the kept forms on /rcfs (rcf.generated_by)
    -- and whose level decided they could upload at all.
    `uploaded_by`   INT UNSIGNED NOT NULL,
    `started_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `applied_at`    DATETIME NULL,

    -- How the files were read, counted from the rows below when the batch
    -- was staged and again when it was kept. On the batch so the list of
    -- uploads can say "6 files, 5 kept" without joining to them.
    `files_read`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `files_ready`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `files_refused` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `files_kept`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `lines_kept`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (`id`),
    -- Staged batches to sweep, and kept batches to list, both newest first.
    KEY `ix_rcf_upload_batch_state` (`applied_at`, `started_at`),
    KEY `ix_rcf_upload_batch_uploader` (`uploaded_by`, `id`),

    CONSTRAINT `fk_rcf_upload_batch_uploader` FOREIGN KEY (`uploaded_by`)
        REFERENCES `app_user` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 3. One uploaded file, as it was read
-- ---------------------------------------------------------------------------

-- Everything the keep needs is RESOLVED HERE, at stage time, and stored:
-- the parsed form in the exact shape RcfStore::store() takes — header, the
-- twenty-five entries with the member numbers reshaped and the names filled
-- in, the team and division the sub-committee label named, the show year
-- the title named — plus every doubt the reader had about it. The keep
-- reads this row and writes; it re-reads nothing, because the file is gone
-- and because the thing the Admin ticked has to be the thing that is kept.
--
-- `form_json` and `warnings_json` are JSON, as `import_staged_row.payload`
-- is: read back by PHP, never queried by key, and shaped by the reader
-- rather than by a column list that would have to move with it.
CREATE TABLE IF NOT EXISTS `rcf_upload_file` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id`       INT UNSIGNED NOT NULL,

    -- 1-based, in the order the browser sent them, which is the order the
    -- Admin picked them in. The preview lists them in this order.
    `position`       SMALLINT UNSIGNED NOT NULL,

    -- basename only: the browser sends whatever it likes here, and this
    -- string is stored and rendered.
    `filename`       VARCHAR(255) NOT NULL DEFAULT '',
    `sha256`         CHAR(64) NOT NULL,
    -- xls, xlsx or csv — decided by the file's bytes, never its name.
    `format`         VARCHAR(8) NOT NULL DEFAULT '',
    `size_bytes`     INT UNSIGNED NOT NULL DEFAULT 0,

    --   ready     read as a form; the preview offers it with a tick box
    --   refused   not a form, or one already kept; never offered
    --   kept      the keep wrote it, and rcf_id says what it became
    --   left_out  the keep ran and this file's box was not ticked
    `status`         ENUM('ready', 'refused', 'kept', 'left_out') NOT NULL DEFAULT 'ready',
    -- Why it was refused, in the sentence the screen prints.
    `refusal`        VARCHAR(500) NOT NULL DEFAULT '',
    -- Whether the box starts ticked. A file that looks like a form already
    -- kept — the same date and the same member numbers — starts unticked
    -- and names the form, so a forwarded copy is not kept twice by default.
    `keep_by_default` TINYINT(1) NOT NULL DEFAULT 1,
    -- The kept form this file looks like, when it looks like one.
    `like_rcf_id`    INT UNSIGNED NULL,

    -- What the header resolved to. Nullable and unconstrained for the team
    -- and the division, as on `rcf` and for the same reason; the show year
    -- is a real foreign key because the keep writes it into rcf.show_year_id,
    -- which is one.
    `show_year_id`   INT UNSIGNED NULL,
    `division_id`    INT UNSIGNED NULL,
    `team_id`        INT UNSIGNED NULL,

    -- The Division Chairman's number from the "CHANGE FORM #" box, when the
    -- uploaded form carried one. Written to every line's serial on keep.
    `serial`         VARCHAR(32) NOT NULL DEFAULT '',
    `form_date`      DATE NULL,
    `row_count`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `warnings_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    `form_json`      JSON NULL,
    `warnings_json`  JSON NULL,

    -- The form this file became, once kept. RESTRICT: a kept form cannot
    -- be deleted (nothing deletes one), and if anything ever tried, the
    -- database would refuse out loud rather than orphan the upload record.
    `rcf_id`         INT UNSIGNED NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `ux_rcf_upload_file_position` (`batch_id`, `position`),
    -- "Was this file uploaded before, in any batch" — the duplicate check,
    -- and the way back from a form to its upload.
    KEY `ix_rcf_upload_file_sha` (`sha256`),
    KEY `ix_rcf_upload_file_rcf` (`rcf_id`),
    KEY `ix_rcf_upload_file_year` (`show_year_id`),
    KEY `ix_rcf_upload_file_like` (`like_rcf_id`),

    -- RESTRICT rather than CASCADE, as 009: discard deletes the files first
    -- and then the batch, explicitly, so a DELETE of a batch that still has
    -- files is refused rather than quietly taking them with it.
    CONSTRAINT `fk_rcf_upload_file_batch` FOREIGN KEY (`batch_id`)
        REFERENCES `rcf_upload_batch` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_upload_file_year` FOREIGN KEY (`show_year_id`)
        REFERENCES `show_year` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_upload_file_rcf` FOREIGN KEY (`rcf_id`)
        REFERENCES `rcf` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_rcf_upload_file_like` FOREIGN KEY (`like_rcf_id`)
        REFERENCES `rcf` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
