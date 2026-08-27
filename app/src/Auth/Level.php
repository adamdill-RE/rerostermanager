<?php

declare(strict_types=1);

namespace Rerm\Auth;

/**
 * The five access levels (spec 4.1), as the database spells them.
 *
 * The backing values are exactly the ENUM in `member.title_level`,
 * `app_user.level` and `app_user.granted_level`, so a level crosses the PDO
 * boundary in one direction as ->value and comes back through from().
 *
 * **Rank comparison belongs here and never in SQL.** The column is declared
 * low to high so ORDER BY sorts correctly, but a WHERE clause comparing level
 * strings compares them alphabetically, where 'officer' > 'admin' — which is
 * not the ordering anybody means. atLeast() is the only comparison in the
 * application.
 *
 * Phase 3 adds Capability and Access beside this, transcribed a second time in
 * tests/access_test.php. Phase 2 needs only the enum itself, because the
 * import writes member.title_level on every row.
 */
enum Level: string
{
    case Member           = 'member';
    case Officer          = 'officer';
    case SeniorOfficer    = 'senior_officer';
    case ExecutiveOfficer = 'executive_officer';
    case Admin            = 'admin';

    /** Higher is broader. Only ever compared through atLeast(). */
    public function rank(): int
    {
        return match ($this) {
            self::Member           => 1,
            self::Officer          => 2,
            self::SeniorOfficer    => 3,
            self::ExecutiveOfficer => 4,
            self::Admin            => 5,
        };
    }

    public function atLeast(self $floor): bool
    {
        return $this->rank() >= $floor->rank();
    }

    /** What a screen calls it. */
    public function label(): string
    {
        return match ($this) {
            self::Member           => 'Member',
            self::Officer          => 'Officer',
            self::SeniorOfficer    => 'Senior Officer',
            self::ExecutiveOfficer => 'Executive Officer',
            self::Admin            => 'Admin',
        };
    }

    /**
     * Does holding this level, by title, mean an account exists?
     *
     * Member is data, not a user: 1,758 of the 1,954 in the sample have no row
     * in app_user at all (spec 3.1). Not a disabled account — no account. An
     * individual may still be granted one as an Allowed User (spec 4.4), which
     * is a different mechanism and is durable against every import.
     */
    public function grantsLogin(): bool
    {
        return $this !== self::Member;
    }
}
