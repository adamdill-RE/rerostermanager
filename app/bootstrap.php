<?php

declare(strict_types=1);

/**
 * The single entry point for every PHP process in this application: the web
 * front controller, every bin/ script, and every test that needs a database.
 *
 *     $app = require __DIR__ . '/../app/bootstrap.php';
 *
 * It registers the autoloader and returns a booted Rerm\App. Requiring it a
 * second time returns the same instance rather than rebuilding one, so a test
 * file and the runner that included it share one connection.
 *
 * There is no Composer here (CLAUDE.md), so the autoloader is fifteen lines
 * rather than a vendor directory. The mapping is flat and literal:
 *
 *     Rerm\Auth\Access      ->  app/src/Auth/Access.php
 *     Rerm\Roster\XlsReader ->  app/src/Roster/XlsReader.php
 */

use Rerm\App;

if (!defined('RERM_ROOT')) {
    define('RERM_ROOT', dirname(__DIR__));

    spl_autoload_register(static function (string $class): void {
        // Ours only. Anything else belongs to another autoloader, or to
        // nobody, and swallowing it here would turn a typo into a confusing
        // "class not found" from a different file.
        if (!str_starts_with($class, 'Rerm\\')) {
            return;
        }

        $relative = str_replace('\\', '/', substr($class, strlen('Rerm\\')));
        $file     = RERM_ROOT . '/app/src/' . $relative . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}

/**
 * Escapes a value for HTML.
 *
 * Global and two letters because it wraps every single rendered value in this
 * application, and a helper that is tedious to type is a helper somebody
 * skips. ENT_QUOTES covers attribute contexts; ENT_SUBSTITUTE means a byte
 * sequence that is not valid UTF-8 renders as U+FFFD rather than returning an
 * empty string and silently deleting the field it was supposed to escape.
 */
if (!function_exists('e')) {
    function e(string|int|float|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** @var Rerm\App|null $GLOBALS['rerm_app'] */
if (!isset($GLOBALS['rerm_app'])) {
    $GLOBALS['rerm_app'] = App::boot(RERM_ROOT);
}

return $GLOBALS['rerm_app'];
