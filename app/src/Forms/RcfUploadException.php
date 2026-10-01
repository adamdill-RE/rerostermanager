<?php

declare(strict_types=1);

namespace Rerm\Forms;

use RuntimeException;

/**
 * An upload that cannot go on as asked (Phase 13, spec-v2 §14): no files
 * arrived, no show year is active to file the forms under, a batch that has
 * already been kept is asked to keep again, or one that was kept is asked
 * to discard. The message is written for the screen.
 */
final class RcfUploadException extends RuntimeException
{
}
