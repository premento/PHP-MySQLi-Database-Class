<?php

use PHPUnit\Framework\TestCase;

/**
 * MysqliDb wired to a stub connection but using the *real* _prepareQuery(), so the
 * mysqli error-mode handling in that method is actually exercised.
 */
class RealPrepareMysqliDb extends MysqliDb
{
    /** @var FakeMysqli */
    public $fake;

    /** @var int How many times connect() was called. */
    public $connectCalls = 0;

    public function __construct()
    {
        parent::__construct('localhost', 'user', 'pass', 'testdb');
        $this->fake = new FakeMysqli();
        $this->_mysqli['default'] = $this->fake;
    }

    public function mysqli()
    {
        return $this->fake;
    }

    public function connect($connectionName = 'default')
    {
        $this->connectCalls++;
        // Pretend the reconnect succeeded and the server is healthy again.
        $this->fake->prepareThrows = null;
        $this->fake->prepareReturnsFalse = false;
        $this->fake->errno = 0;
    }

    public function buildPrepared($sql)
    {
        $this->_query = $sql;

        return $this->_prepareQuery();
    }
}

/**
 * Since PHP 8.1 mysqli throws mysqli_sql_exception instead of returning false. Before
 * this was handled, _prepareQuery()'s own error reporting and the whole autoReconnect
 * feature were unreachable on any modern PHP.
 */
class PrepareErrorModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MysqliDb::$prefix = '';
    }

    public function testPrepareFailureIsReportedWithTheQueryText()
    {
        $db = new RealPrepareMysqliDb();
        $db->fake->prepareThrows = 'You have an error in your SQL syntax';
        $db->fake->errno = 1064;

        try {
            $db->buildPrepared('SELECT * FROM bogus(');
            $this->fail('Expected _prepareQuery() to throw');
        } catch (mysqli_sql_exception $e) {
            $this->fail('mysqli_sql_exception leaked out of the library');
        } catch (Exception $e) {
            $this->assertStringContainsString('You have an error in your SQL syntax', $e->getMessage());
            $this->assertStringContainsString('SELECT * FROM bogus(', $e->getMessage());
            $this->assertSame(1064, $e->getCode());
        }
    }

    public function testPrepareFailureIsAlsoHandledWhenMysqliReturnsFalse()
    {
        $db = new RealPrepareMysqliDb();
        $db->fake->prepareReturnsFalse = true;
        $db->fake->error = 'legacy failure';
        $db->fake->errno = 1064;

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('legacy failure');

        $db->buildPrepared('SELECT 1');
    }

    /**
     * "MySQL server has gone away" (2006) must trigger exactly one reconnect attempt.
     */
    public function testServerGoneAwayTriggersOneReconnect()
    {
        $db = new RealPrepareMysqliDb();
        $db->fake->prepareThrows = 'MySQL server has gone away';
        $db->fake->errno = 2006;

        $stmt = $db->buildPrepared('SELECT 1');

        $this->assertSame(1, $db->connectCalls, 'exactly one reconnect attempt');
        $this->assertInstanceOf(FakeStatement::class, $stmt, 'the retry returns a usable statement');
    }

    public function testReconnectIsNotAttemptedWhenDisabled()
    {
        $db = new RealPrepareMysqliDb();
        $db->autoReconnect = false;
        $db->fake->prepareThrows = 'MySQL server has gone away';
        $db->fake->errno = 2006;

        try {
            $db->buildPrepared('SELECT 1');
            $this->fail('Expected _prepareQuery() to throw');
        } catch (Exception $e) {
            $this->assertSame(0, $db->connectCalls);
        }
    }

    public function testSuccessfulPrepareReturnsTheStatement()
    {
        $db = new RealPrepareMysqliDb();

        $stmt = $db->buildPrepared('SELECT 1');

        $this->assertInstanceOf(FakeStatement::class, $stmt);
        $this->assertSame(array('SELECT 1'), $db->fake->preparedQueries);
    }

    /**
     * An unprepared statement (LOCK, LOAD DATA) goes through queryUnprepared(), which
     * has the same two error conventions to deal with.
     */
    public function testUnpreparedQueryFailureIsTranslated()
    {
        $db = new RealPrepareMysqliDb();
        $db->fake->queryResult = false;
        $db->fake->errno = 1142;
        $db->fake->error = 'access denied';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unprepared Query Failed');

        $db->setLockMethod('READ');
        $db->lock('users');
    }
}
