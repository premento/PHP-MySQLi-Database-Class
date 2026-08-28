<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for the database-free unit tests.
 */
abstract class BuilderTestCase extends TestCase
{
    /** @var TestableMysqliDb */
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        // MysqliDb::$prefix is static, so it leaks between tests unless reset.
        MysqliDb::$prefix = '';
        $this->db = new TestableMysqliDb('localhost', 'user', 'pass', 'testdb');
    }

    protected function tearDown(): void
    {
        MysqliDb::$prefix = '';
        parent::tearDown();
    }

    /**
     * Assert on generated SQL while ignoring incidental whitespace.
     */
    protected function assertSqlEquals($expected, $actual, $message = '')
    {
        $this->assertSame(
            TestableMysqliDb::normalize($expected),
            TestableMysqliDb::normalize($actual),
            $message
        );
    }

    /**
     * Assert that a fragment appears in the generated SQL, whitespace-insensitively.
     */
    protected function assertSqlContains($needle, $haystack, $message = '')
    {
        $this->assertStringContainsString(
            TestableMysqliDb::normalize($needle),
            TestableMysqliDb::normalize($haystack),
            $message
        );
    }
}
