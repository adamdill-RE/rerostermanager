<?php

declare(strict_types=1);

namespace Rerm\Forms;

use RuntimeException;

/**
 * An uploaded file that cannot be read as a Roster Change Form at all
 * (Phase 13, spec-v2 §14.3): not a spreadsheet, no sheet carrying the
 * form's column headers, or a form with nothing filled in.
 *
 * The message is written for the screen — it names what was looked for and
 * not found — because the person reading it has a pile of email attachments
 * and needs to know WHICH one was not a form, not that one was.
 */
final class RcfReadException extends RuntimeException
{
}
