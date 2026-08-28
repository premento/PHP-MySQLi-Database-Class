<?php
/**
 * PHPUnit bootstrap.
 *
 * The unit suite never touches a database: it drives the query builder through a
 * stub statement and asserts on the SQL and bind parameters that come out.
 * The integration suite talks to a real MySQL server and skips itself when one is
 * not configured (see tests/integration/IntegrationTestCase.php).
 */

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run 'composer install' first.\n");
    exit(1);
}

require_once $autoload;
