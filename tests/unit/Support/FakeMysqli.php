<?php

/**
 * Stand-in for the mysqli connection object.
 *
 * Only the members MysqliDb actually reaches for are implemented.
 */
class FakeMysqli
{
    /** @var int */
    public $errno = 0;

    /** @var string */
    public $error = '';

    /** @var int */
    public $connect_errno = 0;

    /** @var string */
    public $connect_error = '';

    /** @var int */
    public $insert_id = 0;

    /** @var string[] Queries run through query() (i.e. unprepared). */
    public $unpreparedQueries = array();

    /** @var bool|null Last value passed to autocommit(). */
    public $autocommitState = null;

    /** @var int */
    public $commitCount = 0;

    /** @var int */
    public $rollbackCount = 0;

    /** @var mixed Return value for query(). */
    public $queryResult = true;

    /** @var string[] Queries passed to prepare(). */
    public $preparedQueries = array();

    /**
     * @var string|null When set, prepare() throws mysqli_sql_exception with this message,
     *                  emulating the PHP 8.1+ default error mode.
     */
    public $prepareThrows = null;

    /** @var bool When true, prepare() returns false (the legacy error convention). */
    public $prepareReturnsFalse = false;

    /** @var mixed Statement returned by a successful prepare(). */
    public $prepareResult = null;

    public function query($query)
    {
        $this->unpreparedQueries[] = $query;

        return $this->queryResult;
    }

    public function prepare($query)
    {
        $this->preparedQueries[] = $query;

        if ($this->prepareThrows !== null) {
            throw new mysqli_sql_exception($this->prepareThrows, $this->errno);
        }

        if ($this->prepareReturnsFalse) {
            return false;
        }

        return $this->prepareResult === null ? new FakeStatement($query) : $this->prepareResult;
    }

    /**
     * Mirrors mysqli::real_escape_string for the default (non multi-byte) case, which is
     * enough for asserting that inlined values cannot break out of a string literal.
     */
    public function real_escape_string($value)
    {
        return str_replace(
            array("\\", "\0", "\n", "\r", "'", '"', "\x1a"),
            array("\\\\", "\\0", "\\n", "\\r", "\\'", '\\"', "\\Z"),
            (string) $value
        );
    }

    public function more_results()
    {
        return false;
    }

    public function next_result()
    {
        return false;
    }

    public function autocommit($mode)
    {
        $this->autocommitState = $mode;

        return true;
    }

    public function commit()
    {
        $this->commitCount++;

        return true;
    }

    public function rollback()
    {
        $this->rollbackCount++;

        return true;
    }

    public function ping()
    {
        return true;
    }

    public function set_charset($charset)
    {
        return true;
    }

    public function close()
    {
        return true;
    }
}
