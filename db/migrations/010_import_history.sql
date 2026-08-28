-- 010_import_history.sql — the durable record of what each import actually
-- changed, field by field, member by member.
--
-- SCHEMA, so NOT atomic: MySQL commits implicitly on DDL and a transaction
-- here would report a rollback that did not happen. IF NOT EXISTS throughout,
-- like 001 and 004, so a run that dies half way can start again from the top.
--
--
-- WHY THIS TABLE EXISTS
--
-- Rodeo Houston's export has no audit trail. It is a snapshot: this is the
-- committee as of the moment somebody pressed Export, with nothing at all
-- about how it got that way. So when a member's team changes, or their title
-- drops from Captain to Committee Member, or they simply stop appearing, the
-- only way to find out when it happened — and therefore which file did it,
-- and therefore who to ask — was to keep the old spreadsheets and diff them
-- by hand.
--
-- `import_batch` already answers the question in aggregate: how many rows a
-- file created, updated and dropped, which metrics moved, which teams were
-- new, how many warnings of each kind. What it cannot answer is the one that
-- gets asked: **which people, and what about them.**
--
-- The parsed diff that would answer it already existed, on
-- `import_staged_row`.`changes`, and it is the wrong place to keep it for two
-- reasons. It is documented as disposable — "a parse of a file, thrown away
-- 24 hours later", with ON DELETE CASCADE to prove it — so building a
-- permanent record on top of it would make a table that discards itself the
-- foundation of one that must not. And it sits beside `payload`, which is the
-- member's whole HLSR-owned record including their address, so answering
-- "when did this team change" would mean reading, and retaining, everything
-- else about them as well.
--
-- This table holds the diff and nothing else: which member, which field, what
-- it was, what it became, and which batch did it. It is written at APPLY
-- time, in the same transaction as the write it describes, so a row here
-- means the roster really changed — not that a preview said it would.
--
--
-- WHAT IT IS NOT
--
-- It is not `audit_log`. That one records what a PERSON did, and every row
-- has an actor. This records what a FILE did: the actor is the same for all
-- of them, is already on `import_batch`.`uploaded_by`, and the interesting
-- column is the one audit_log has no room for — the field name. Keeping
-- 1,954 create rows out of the audit log also keeps the audit log readable,
-- which is the property that makes it worth having.
--
-- It is not a way to undo an import either. Nothing reads this table to write
-- the roster back, and nothing should: a wrong import is fixed by importing
-- the right file, which diffs against the roster as it now stands. This
-- answers "when did that happen", which is the question that was actually
-- being asked.


-- ---------------------------------------------------------------------------
-- One field, on one member, changed by one import
-- ---------------------------------------------------------------------------

-- A `created` or `dropped` row carries no field and no values — the kind IS
-- the whole fact, and inventing a before/after for it would be a value nobody
-- wrote. An `updated` row carries one field, with the value on each side.
--
-- Values are VARCHAR(255), not JSON: they are read by a person on a screen,
-- one line each, and the widest column an import owns is `address` at 160.
-- The application truncates rather than refuses (`Importer::changeValue`),
-- because a history that stops recording when a cell is too long is a history
-- with a hole in it exactly where something unusual happened.
CREATE TABLE IF NOT EXISTS `import_change` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `import_batch_id` INT UNSIGNED NOT NULL,

    -- RESTRICT, like every other foreign key pointing at `member`. Nothing
    -- deletes a member, so nothing can orphan one of these; and if anything
    -- ever tries, the database refuses out loud rather than quietly taking
    -- the history with it.
    --
    -- Nullable defensively rather than because anything writes NULL today:
    -- every path resolves the id inside the transaction that wrote the member
    -- (`Importer::recordChanges` refuses to record a create that did not),
    -- and the number below is kept regardless, so a row written by some
    -- future path that cannot resolve one would still be findable.
    `member_id`       INT UNSIGNED NULL,
    -- Kept even when member_id is set, and deliberately duplicated: this is
    -- the natural key Rodeo Houston uses, it is what somebody types into the
    -- search box, and it is what a row still has if the member row is ever
    -- renumbered.
    `member_number`   VARCHAR(32) NOT NULL DEFAULT '',

    --   created   the roster had nobody with this number, and now does
    --   updated   an HLSR-owned field changed; `field` says which
    --   dropped   the file did not list them, so they were flagged (spec 6.5)
    --   returned  a dropped member appeared in a file again, and was un-flagged
    --
    -- `returned` is its own kind rather than an `updated` row on the flag,
    -- because "who came back" is one of the two questions this table was
    -- built for and it should not need a WHERE on a field name to ask.
    `kind`            ENUM('created', 'updated', 'dropped', 'returned') NOT NULL,

    -- The column as the importer names it internally: `first_name`, `title`,
    -- `team`, `division`, or `metric:show_dues` for one of the five metrics.
    -- Stored raw and labelled for display in PHP, so the vocabulary here is
    -- the vocabulary the diff already speaks and the two cannot drift.
    `field`           VARCHAR(64) NOT NULL DEFAULT '',
    `before_value`    VARCHAR(255) NULL,
    `after_value`     VARCHAR(255) NULL,

    -- The apply's own timestamp, copied rather than joined. The batch has it
    -- too, but every read of this table sorts by it, and a sort that has to
    -- join to order is a sort that gets slow exactly when the history gets
    -- long enough to be worth having.
    `occurred_at`     DATETIME NOT NULL,

    PRIMARY KEY (`id`),

    -- "Everything that ever happened to this member, oldest first" — the
    -- per-member timeline, which is the read this table exists for.
    KEY `ix_import_change_member` (`member_id`, `id`),
    -- The same question asked by number, for a member whose id is not to hand
    -- and for a create that could not resolve one.
    KEY `ix_import_change_number` (`member_number`, `id`),
    -- "What did this import do", and "what did it do to teams" — the batch
    -- screen and its per-field drill-down.
    KEY `ix_import_change_batch` (`import_batch_id`, `kind`, `field`, `id`),
    -- "When did anybody's title last change", across every import.
    KEY `ix_import_change_field` (`field`, `id`),

    CONSTRAINT `fk_import_change_batch` FOREIGN KEY (`import_batch_id`)
        REFERENCES `import_batch` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_import_change_member` FOREIGN KEY (`member_id`)
        REFERENCES `member` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- RESTRICT on the batch is the same decision as everywhere else here, and it
-- has a consequence worth writing down: `Importer::discard()` deletes an
-- unapplied batch, and it does NOT delete from this table. It does not need
-- to, because nothing writes a row here until the apply, and an apply that
-- dies part way marks the batch failed — which `discard()` refuses and the
-- 24-hour sweep skips. If that invariant is ever broken, the database will
-- refuse the delete rather than silently discarding a permanent record, which
-- is the whole reason it is RESTRICT and not CASCADE.
