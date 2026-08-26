-- 003_seed_master_admin.sql — the account that unlocks a brand-new database.
--
-- Pure data.
-- rerm:atomic
--
-- A fresh database has no members, so nobody can be an officer by title and
-- nobody can designate anybody. One account has to exist before the first
-- import can be run. This is it.
--
-- IT SHIPS LOCKED, AND THAT IS THE POINT.
--
-- This repository is PUBLIC — it has to be, because cPanel's Deploy HEAD
-- Commit reads it over HTTPS and this account has no SSH key. Anything
-- committed here is readable by anyone, forever, and git history does not
-- forget. So there is no password hash in this file and there never may be:
-- a bcrypt hash of a weak password is a weak password, published, on an
-- application that will hold ~1,950 people's home addresses and phone numbers.
--
-- password_hash is set to '*' — the /etc/shadow convention for a locked
-- account, and not a hash of anything. password_verify() returns false against
-- it for EVERY input including '*' itself, so the account cannot be
-- authenticated against no matter what is typed. tests/schema_test.php asserts
-- exactly that, over a battery of candidates.
--
-- Unlock it deliberately, once, on the machine that is running the app:
--
--     php bin/set-admin-password.php          (Phase 3)
--     or the /setup route, which exists only while app.setup_key is configured
--
-- email is NULL on purpose as well. Password recovery needs an address on
-- file, so with none there is no emailed route to this account at all — the
-- only way in is the deliberate one above.


-- The member row. Every account belongs to a member, including this one.
--
-- member_number 987654321 is safely outside the observed export range of
-- 151,696 - 2,089,937, so a real roster can never collide with it. It is
-- stored as a string like every other member number: an identifier, never
-- arithmetic.
--
-- The title is one the export's map does not know, so title_level is 'member'
-- — exactly what an unrecognised title imports as. The Admin level comes from
-- granted_level below and from nowhere else, which means the very first
-- account in the database is created by the same Allowed User mechanism every
-- later designation uses, and inherits its durability.
--
-- division_id points at the seeded (No Division) placeholder because
-- member.division_id is NOT NULL and this person is on no division. is_system
-- keeps them out of every roster, roll-up, import and export regardless.
INSERT INTO `member` (
    `member_number`,
    `first_name`, `last_name`, `preferred_name`, `full_name`,
    `email`,
    `title`, `title_level`,
    `division_id`, `team_id`,
    `is_system`, `is_active`, `first_imported_at`
)
SELECT
    '987654321',
    'Master', 'Administrator', 'Master Administrator', 'Master Administrator',
    NULL,
    'Administrator', 'member',
    `d`.`id`, NULL,
    1, 1, NULL
FROM `division` AS `d`
WHERE `d`.`name` = '(No Division)';


-- The account.
--
-- Two statements rather than one because there is no RETURNING here: that is a
-- MariaDB extension and production is MySQL 8.0. An insert that needs its own
-- row back takes a second statement, and this is what that looks like.
--
--   level          'member', the title-derived level, which is what an import
--                  would compute from the title above. Never hand-maintained.
--   granted_level  'admin'. Durable: no import writes it, so effective_level
--                  (granted_level ?? level) stays 'admin' whatever a future
--                  roster decides this person's title is.
--   granted_by     NULL. There was no user to attribute it to — this is the
--                  first account in the database, and a self-reference would
--                  claim it granted itself.
INSERT INTO `app_user` (
    `member_id`,
    `level`, `granted_level`, `granted_by`, `granted_at`,
    `password_hash`, `must_change_password`, `password_changed_at`,
    `is_active`
)
SELECT
    `m`.`id`,
    'member', 'admin', NULL, UTC_TIMESTAMP(),
    '*', 1, NULL,
    1
FROM `member` AS `m`
WHERE `m`.`member_number` = '987654321';


-- Every grant is logged with its actor and its time, and this one is a grant.
-- The actor is NULL because a migration made it; recording it anyway means the
-- audit log can answer "where did this Admin come from" without anybody having
-- to know that the first one is special.
INSERT INTO `audit_log` (`actor_user_id`, `action`, `entity`, `entity_id`, `before_json`, `after_json`, `ip`)
SELECT
    NULL,
    'grant_level',
    'app_user',
    CAST(`u`.`id` AS CHAR),
    NULL,
    '{"granted_level":"admin","source":"migration 003_seed_master_admin.sql","password":"locked, not set"}',
    ''
FROM `app_user` AS `u`
INNER JOIN `member` AS `m` ON `m`.`id` = `u`.`member_id`
WHERE `m`.`member_number` = '987654321';
