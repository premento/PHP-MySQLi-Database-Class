<?php

/**
 * Stand-in for mysqli_stmt used by the unit suite.
 *
 * It deliberately does NOT extend mysqli_stmt: that class cannot be instantiated
 * without a live connection, and its native property handlers throw
 * "object is already closed" for any instance built without one.
 */
class FakeStatement
{
    /** @var string The query this statement was prepared from. */
    public $query = '';

    /** @var string The type string passed to bind_param(). */
    public $boundTypes = '';

    /** @var array The values passed to bind_param(). */
    public $boundValues = array();

    /** @var bool Whether bind_param() was called at all. */
    public $bindCalled = false;

    /** @var bool Whether close() was called. */
    public $closed = false;

    /** @var int Number of times execute() was called. */
    public $executeCount = 0;

    // --- mysqli_stmt surface used by MysqliDb -------------------------------

    /** @var string */
    public $error = '';

    /** @var int */
    public $errno = 0;

    /** @var string */
    public $sqlstate = '00000';

    /** @var int */
    public $affected_rows = 1;

    /** @var int */
    public $insert_id = 0;

    // --- test knobs ---------------------------------------------------------

    /** @var bool Return value for execute(). */
    public $executeResult = true;

    /** @var string|null When set, execute() throws mysqli_sql_exception with this message. */
    public $throwOnExecute = null;

    /**
     * Rows this statement serves, as a list of column => value maps. While this is null
     * the statement reports no result metadata and MysqliDb treats it as a write.
     *
     * @var array|null
     */
    public $resultRows = null;

    /** @var string Table name reported for every field of the result set. */
    public $resultTable = '';

    /** @var array References handed over by bind_result(). */
    private $boundResultRefs = array();

    /** @var int Index of the next row fetch() will serve. */
    private $fetchCursor = 0;

    public function __construct($query = '')
    {
        $this->query = $query;
    }

    public function bind_param($types, &...$values)
    {
        $this->bindCalled = true;
        $this->boundTypes = $types;
        $this->boundValues = $values;

        return true;
    }

    public function execute()
    {
        $this->executeCount++;

        if ($this->throwOnExecute !== null) {
            $this->error = $this->throwOnExecute;
            $this->errno = 1064;
            throw new mysqli_sql_exception($this->throwOnExecute, 1064);
        }

        return $this->executeResult;
    }

    /**
     * Returning false makes MysqliDb treat the statement as a non-SELECT, which is what
     * the builder-level tests want: no result set to bind. Setting $resultRows turns the
     * statement into a SELECT so the row-mapping code can be exercised too.
     */
    public function result_metadata()
    {
        if ($this->resultRows === null) {
            return false;
        }

        $columns = empty($this->resultRows) ? array() : array_keys($this->resultRows[0]);

        return new FakeResultMetadata($columns, $this->resultTable);
    }

    public function store_result()
    {
        return true;
    }

    public function free_result()
    {
        return true;
    }

    /**
     * mysqli_stmt::bind_result() takes its targets by reference and writes each fetched
     * column into them, so the references have to be kept rather than the values.
     */
    public function bind_result(&...$refs)
    {
        $this->boundResultRefs = array();

        foreach ($refs as $i => &$ref) {
            $this->boundResultRefs[$i] = &$ref;
        }
        unset($ref);

        return true;
    }

    public function fetch()
    {
        if ($this->resultRows === null || $this->fetchCursor >= count($this->resultRows)) {
            return null;
        }

        $values = array_values($this->resultRows[$this->fetchCursor]);
        $this->fetchCursor++;

        foreach ($values as $i => $value) {
            if (array_key_exists($i, $this->boundResultRefs)) {
                $this->boundResultRefs[$i] = $value;
            }
        }

        return true;
    }

    public function close()
    {
        $this->closed = true;

        return true;
    }
}
