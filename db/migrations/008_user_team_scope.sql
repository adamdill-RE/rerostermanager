-- 008_user_team_scope.sql — a scope that is a SET of teams.
--
-- SCHEMA, so NOT atomic: MySQL commits implicitly on DDL. CREATE TABLE IF NOT
-- EXISTS makes the one statement here safe to re-run from the top.
--
--
-- WHY A TABLE AND NOT A COLUMN
--
-- `app_user` has carried `scope_team_id` since 001 — ONE team, nullable, the
-- Admin override for an Officer. The committee turns out not to be shaped
-- that way: some Vice Chairmen cover several teams and some cover one, and
-- spec 4.3's two shapes (a whole division, or a single team) have no room for
-- "these three".
--
-- A list does not fit in a column, so it gets a table. `scope_team_id` stays
-- exactly as it is and keeps meaning what it meant: the single-team override
-- for an Officer. This is the new, separate thing.
--
--
-- WHO IT APPLIES TO, AND WHO IT MUST NOT
--
-- Senior Officer and above ONLY (settled with the owner, 28 August). The
-- storage is general because a row in a join table costs nothing, but nothing
-- below Senior Officer is offered a team set or has one honoured — an Officer
-- already has a single-team scope that works, and a second shape at that
-- level is surface nobody has asked for.
--
-- The rule itself lives in PHP, in `Rerm\Auth\User` where the scope is
-- resolved once for both readers, not in this table. A table cannot enforce
-- "only for levels at or above Senior Officer" without a trigger, and a
-- trigger is a second place the rule would live.


CREATE TABLE IF NOT EXISTS `app_user_team` (
    `app_user_id` INT UNSIGNED NOT NULL,
    `team_id`     INT UNSIGNED NOT NULL,

    -- Who widened this person, and when. The audit_log row is the record;
    -- these two exist so the table can answer "how did this get here" without
    -- a join to a log that may have been filtered.
    `granted_by`  INT UNSIGNED NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- The pair IS the identity: one row per user per team, and re-adding a
    -- team somebody already has is a no-op rather than a duplicate.
    PRIMARY KEY (`app_user_id`, `team_id`),
    KEY `ix_app_user_team_team` (`team_id`),
    KEY `ix_app_user_team_granted_by` (`granted_by`),

    -- RESTRICT like every other foreign key here. Retiring a team is
    -- `is_active = 0`, never a DELETE, so nothing should ever be trying to
    -- remove a team a scope points at — and if something is, it should stop
    -- rather than silently widen somebody by deleting their narrowing.
    CONSTRAINT `fk_app_user_team_user` FOREIGN KEY (`app_user_id`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_app_user_team_team` FOREIGN KEY (`team_id`) REFERENCES `team` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_app_user_team_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `app_user` (`id`)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
