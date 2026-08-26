<?php

declare(strict_types=1);

/**
 * A small test runner.
 *
 * The host has no Composer and no build step (CLAUDE.md), so PHPUnit is not
 * available and is not worth the dependency. This gives the two things that
 * actually matter: a non-zero exit code when something breaks, and a message
 * that names what broke.
 *
 *   php tests/run.php              run every test
 *   php tests/run.php spreadsheet  run files matching "spreadsheet"
 *   php tests/run.php --strict     treat a skipped test as a failure
 *
 * --strict is what CI runs. Without it a test that skips because a fixture or
 * a database is missing would still report success — green, having checked
 * nothing.
 */

final class SkippedTest extends RuntimeException
{
}

final class TestRunner
{
    /** @var array<int, array{name: string, fn: callable}> */
    private static array $tests = [];

    private static int $passed = 0;

    /** @var array<int, string> */
    private static array $failures = [];

    /** @var array<int, string> */
    private static array $skipped = [];

    public static function add(string $name, callable $fn): void
    {
        self::$tests[] = ['name' => $name, 'fn' => $fn];
    }

    public static function run(bool $strict): int
    {
        foreach (self::$tests as $test) {
            try {
                ($test['fn'])();
                self::$passed++;
                fwrite(STDOUT, '.');
            } catch (SkippedTest $e) {
                self::$skipped[] = $test['name'] . ' — ' . $e->getMessage();
                fwrite(STDOUT, 's');
            } catch (Throwable $e) {
                self::$failures[] = sprintf(
                    "%s\n    %s\n    at %s:%d",
                    $test['name'],
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                );
                fwrite(STDOUT, 'F');
            }
        }

        fwrite(STDOUT, "\n\n");

        foreach (self::$failures as $failure) {
            fwrite(STDERR, "FAILED  {$failure}\n\n");
        }
        foreach (self::$skipped as $skip) {
            fwrite(STDOUT, "skipped {$skip}\n");
        }

        printf(
            "%d passed, %d failed, %d skipped\n",
            self::$passed,
            count(self::$failures),
            count(self::$skipped)
        );

        if (self::$failures !== []) {
            return 1;
        }
        if ($strict && self::$skipped !== []) {
            fwrite(STDERR, "\n--strict: a skipped test is a failure.\n");

            return 1;
        }

        return 0;
    }
}

function test(string $name, callable $fn): void
{
    TestRunner::add($name, $fn);
}

function skip(string $why): never
{
    throw new SkippedTest($why);
}

/** @param mixed $expected @param mixed $actual */
function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%sexpected %s, got %s",
            $message === '' ? '' : $message . ': ',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Asserts the callable throws, and that the message contains $needle. */
function assertThrows(callable $fn, string $needle = '', string $message = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($needle !== '' && !str_contains($e->getMessage(), $needle)) {
            throw new RuntimeException(sprintf(
                '%sthrew, but the message did not contain %s. Got: %s',
                $message === '' ? '' : $message . ': ',
                var_export($needle, true),
                $e->getMessage()
            ));
        }

        return;
    }

    throw new RuntimeException(($message === '' ? '' : $message . ': ') . 'expected an exception, none thrown');
}

$options = array_slice($argv, 1);
$strict  = in_array('--strict', $options, true);
$filter  = null;
foreach ($options as $option) {
    if (!str_starts_with($option, '--')) {
        $filter = $option;
    }
}

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    if ($filter !== null && !str_contains(basename($file), $filter)) {
        continue;
    }
    require $file;
}

exit(TestRunner::run($strict));
