<?php

declare(strict_types=1);

namespace Rerm\Import;

use RuntimeException;

/**
 * The import refused to run, and the message says why in words an Admin can
 * act on.
 *
 * Distinct from a warning, and the distinction is the whole of spec 6.4: a
 * warning is never fatal and is attributed to a row, while this stops the
 * import before anything is staged. There are only a few reasons for it — no
 * required column, no active show year, a closed show year, a batch that was
 * already applied — and every one of them is a thing the Admin has to change
 * before trying again.
 *
 * Every message here is rendered to a browser, so it is written for the person
 * holding the file rather than for a log.
 */
final class ImportException extends RuntimeException
{
}
