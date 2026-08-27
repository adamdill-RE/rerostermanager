-- 006_seed_team_area.sql — the fourth level in the team names, given its column.
--
-- Pure data. Not one statement here is DDL: `team`.`area` and its index
-- shipped in 001, and so did the rule that no import ever writes them. Only
-- the seeding never did, which left the middle level of the Committee
-- Dashboard's roll-up (spec 7.3) with nothing to group by.
-- rerm:atomic
--
--
-- WHAT AREA IS, AND WHAT IT IS NOT
--
-- The export has four levels of topology and a column for three of them
-- (docs/data-findings.md 4d): team names encode an area, and the eight
-- Division Vice Chairmen run those areas rather than divisions. The area
-- leadership sits in a team named after the bare area — `Reed Road` (3
-- people), `610` (3), `Emlr` (4), `Bus Ops` (7), `Ost-Smith Lands` (2),
-- `Chuckwagon` (2) and `Administration` (4).
--
-- Those seven bare names ARE the area list. Every other team takes the
-- LONGEST of them its own name starts with, which is what the CASE below
-- spells: its branches run longest name first, so the first match is the
-- longest match whatever is added to the list later.
--
-- A team matching none of them keeps NULL and groups under `(No area)` on the
-- dashboard — the same honest-placeholder pattern as `(No Division)`, and for
-- the same reason: a bucket that is visibly empty of meaning beats a roll-up
-- that quietly omits somebody. `Lifetime`, `Special Projects` and
-- `E&M Special Projects` are the shape that lands there.
--
-- This column is DISPLAY GROUPING AND NOTHING ELSE. It is seeded by the
-- heuristic below and Phase 8's Manage Teams screen makes it editable by an
-- Admin, so anything that read it for a permission decision would move with a
-- cosmetic edit. It must never appear in Rerm\Auth\Access, ScopedQuery,
-- EligibleOfficers or AssignOfficers; tests/access_test.php asserts that over
-- the SOURCE of all four, comments included.
--
-- WHERE `area` IS NULL is what makes this a SEED rather than a rewrite: it
-- fills in what nobody has decided, and a later Admin edit is never undone by
-- re-running a restore of this file into a database that already has one.
UPDATE `team`
SET `area` = CASE
    WHEN `name` LIKE 'Ost-Smith Lands%' THEN 'Ost-Smith Lands'
    WHEN `name` LIKE 'Administration%'  THEN 'Administration'
    WHEN `name` LIKE 'Chuckwagon%'      THEN 'Chuckwagon'
    WHEN `name` LIKE 'Reed Road%'       THEN 'Reed Road'
    WHEN `name` LIKE 'Bus Ops%'         THEN 'Bus Ops'
    WHEN `name` LIKE 'Emlr%'            THEN 'Emlr'
    WHEN `name` LIKE '610%'             THEN '610'
    ELSE NULL
END
WHERE `area` IS NULL;


-- And the one line that ends "Master Administrator Administrator".
--
-- 003 seeded the account with first_name 'Master', last_name 'Administrator'
-- and preferred_name 'Master Administrator', and every list in the
-- application calls a member by RosterPage::displayName() — preferred name,
-- else first name, then the last name. So the seeded account reads its own
-- surname twice, everywhere it appears.
--
-- The standing rule holds: no migration is added SOLELY to fix a cosmetic
-- string. This is the first pure-data migration since it was noticed, so it
-- travels here (spec 7.3).
--
-- Scoped by is_system as well as by member number: 987654321 is outside the
-- observed export range of 151,696 - 2,089,937 and cannot collide, and the
-- second condition means that even if it somehow did, a real committee
-- member's name is not what this rewrites.
UPDATE `member` SET `preferred_name` = 'Master'
WHERE `member_number` = '987654321' AND `is_system` = 1;
