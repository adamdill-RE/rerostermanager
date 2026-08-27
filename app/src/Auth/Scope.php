<?php

declare(strict_types=1);

namespace Rerm\Auth;

/**
 * How far a capability reaches (spec 4.5). The meaning of each case is
 * enforced in exactly one place, Rerm\Auth\Access.
 */
enum Scope
{
    /** The signed-in user's own member row and nothing else. */
    case Own;

    /** The user's division (Senior Officer) or team (Officer). */
    case Scoped;

    /** The whole committee. Admin capabilities, no subject involved. */
    case Everywhere;
}
