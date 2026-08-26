-- 002_seed_reference.sql — divisions and the first show year.
--
-- Pure data, so it may take a transaction. Nothing here is DDL; the migrator
-- refuses this directive on a file that contains any, because MySQL commits
-- implicitly on DDL and a transaction around one would report a rollback that
-- did not happen.
-- rerm:atomic
--
-- Plain INSERTs rather than INSERT IGNORE or ON DUPLICATE KEY: this file runs
-- exactly once, and if it fails the transaction takes all of it back, so the
-- next attempt starts from nothing. INSERT IGNORE would also have downgraded
-- a real error — a truncated name, a bad enum — into a warning nobody reads.


-- The four divisions in the Rodeo Houston export, seeded rather than left to
-- be created by the first import, so that an import matches an existing row
-- instead of inventing one and a fresh database has a legible dashboard before
-- any roster is loaded.
--
-- Member Services Division is here because the export contains it, not because
-- it is operational: its 10 people are 1 Officer in Charge, 8 Lifetime Vice
-- Presidents and 2 Lifetime Committeemen — zero ordinary Committee Members.
INSERT INTO `division` (`name`, `is_placeholder`, `is_active`) VALUES
    ('Satellites Division',      0, 1),
    ('Bus Ops Division',         0, 1),
    ('Logistics Division',       0, 1),
    ('Member Services Division', 0, 1);


-- And the fifth, which is OURS.
--
-- 72 members arrive with a blank Subcommittee 3 — 57 honorary members parked
-- in a `Lifetime` pseudo-team and 15 ordinary Committee Members on real teams.
-- They land here rather than in a NULL, which buys three things a NULL could
-- not: member.division_id stays NOT NULL so no query carries a null branch and
-- no roll-up can quietly omit a bucket; a Senior Officer can be SCOPED to it,
-- giving those 15 members an owner; and it sorts, groups and drills down on
-- the Committee Dashboard like any other division.
--
-- is_placeholder is what keeps it honest, and three rules follow from it:
--
--   1. Every import re-evaluates membership. A populated Subcommittee 3 moves
--      the member out to the real division; a blank one moves them in. Never
--      sticky.
--   2. The export writes it back as BLANK, never as the literal text below.
--      It is our bookkeeping, not Rodeo Houston's data, and it must not travel
--      back to them as though it were theirs.
--   3. The no_division import warning still fires. The bucket makes those
--      members reachable; it does not make them correctly placed.
--
-- tests/schema_test.php asserts the row exists and is flagged.
INSERT INTO `division` (`name`, `is_placeholder`, `is_active`) VALUES
    ('(No Division)', 1, 1);


-- The first show year, active and open.
--
-- Everything metric-, contact- and assignment-related is keyed to one of
-- these, so a database with none cannot record anything and the import has
-- nowhere to write. Exactly one row is active at a time and the schema
-- enforces it through a VIRTUAL uniqueness key.
--
-- The dates are left NULL deliberately. The label names the show this roster
-- is being chased for; an Admin sets the dates and opens the next year from
-- the Show Year screen, and a date invented by a migration would read as fact.
INSERT INTO `show_year` (`label`, `starts_on`, `ends_on`, `is_open`, `is_active`) VALUES
    ('2027', NULL, NULL, 1, 1);
