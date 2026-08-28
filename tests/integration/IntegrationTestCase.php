<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real MySQL/MariaDB server.
 *
 * The suite skips itself unless DB_HOST and DB_NAME are set, so `composer test` stays
 * runnable on a machine with no database. Configure it with:
 *
 *   DB_HOST=127.0.0.1 DB_USER=root DB_PASS=secret DB_NAME=testdb vendor/bin/phpunit
 *
 * Every test runs against tables prefixed with `phpunit_` and drops them again in
 * tearDown, so it will not disturb other data in the schema.
 */
abstract class IntegrationTestCase extends TestCase
{
    /** @var MysqliDb */
    protected $db;

    /** @var string */
    protected $prefix = 'phpunit_';

    /** @var string[] Tables created by the current test, dropped in tearDown. */
    protected $createdTables = array();

    public static function dbConfig()
    {
        return array(
            'host' => getenv('DB_HOST') ?: null,
            'username' => getenv('DB_USER') ?: 'root',
            'password' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
            'db' => getenv('DB_NAME') ?: null,
            'port' => getenv('DB_PORT') ? (int) getenv('DB_PORT') : 3306,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('The mysqli extension is not available.');
        }

        $config = self::dbConfig();

        if (empty($config['host']) || empty($config['db'])) {
            $this->markTestSkipped(
                'Set DB_HOST and DB_NAME (optionally DB_USER, DB_PASS, DB_PORT) to run the integration suite.'
            );
        }

        MysqliDb::$prefix = '';

        try {
            $this->db = new MysqliDb(
                $config['host'],
                $config['username'],
                $config['password'],
                $config['db'],
                $config['port']
            );
            $this->db->ping();
        } catch (Exception $e) {
            $this->markTestSkipped('Could not connect to the test database: ' . $e->getMessage());
        }

        MysqliDb::$prefix = $this->prefix;
    }

    protected function tearDown(): void
    {
        if ($this->db instanceof MysqliDb) {
            foreach (array_reverse($this->createdTables) as $table) {
                try {
                    // Raw name: the prefix is already part of $table.
                    $this->db->rawQuery('DROP TABLE IF EXISTS `' . $table . '`');
                } catch (Exception $e) {
                    // Nothing useful to do while tearing down.
                }
            }
            $this->db->disconnectAll();
        }

        $this->createdTables = array();
        MysqliDb::$prefix = '';

        parent::tearDown();
    }

    /**
     * Create a table for the duration of the test. $name is given without the prefix.
     *
     * @param string $name
     * @param string $definition Column definitions, e.g. "id INT, login VARCHAR(10)"
     */
    protected function createTable($name, $definition)
    {
        $table = $this->prefix . $name;

        $this->db->rawQuery('DROP TABLE IF EXISTS `' . $table . '`');
        $this->db->rawQuery('CREATE TABLE `' . $table . '` (' . $definition . ')');

        $this->createdTables[] = $table;

        return $table;
    }

    /**
     * A standard users table used by several tests.
     */
    protected function createUsersTable()
    {
        return $this->createTable('users', '
            id INT AUTO_INCREMENT PRIMARY KEY,
            login VARCHAR(32) NOT NULL,
            customerId INT NULL,
            active TINYINT(1) NOT NULL DEFAULT 0,
            score DOUBLE NULL,
            note TEXT NULL,
            createdAt DATETIME NULL,
            UNIQUE KEY login (login)
        ');
    }
}
