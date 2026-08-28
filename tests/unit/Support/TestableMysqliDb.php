<?php

/**
 * MysqliDb wired to stub mysqli objects so the query builder can be asserted on
 * without a database server.
 */
class TestableMysqliDb extends MysqliDb
{
    /** @var FakeMysqli */
    public $fake;

    /** @var FakeStatement[] Every statement handed out by _prepareQuery(). */
    public $statements = array();

    /** @var string[] Every query that reached _prepareQuery(). */
    public $preparedQueries = array();

    /** @var int affected_rows reported by the next statement. */
    public $nextAffectedRows = 1;

    /** @var int insert_id reported by the next statement. */
    public $nextInsertId = 0;

    /** @var bool execute() result for the next statement. */
    public $nextExecuteResult = true;

    /** @var string|null When set, the next execute() throws mysqli_sql_exception. */
    public $nextExecuteThrows = null;

    public function __construct($host = null, $username = null, $password = null, $db = null, $port = null, $charset = 'utf8', $socket = null)
    {
        parent::__construct($host, $username, $password, $db, $port, $charset, $socket);
        $this->fake = new FakeMysqli();
        // Register it as the live connection too: getLastError() reads $_mysqli directly
        // rather than going through mysqli().
        $this->_mysqli['default'] = $this->fake;
    }

    public function mysqli()
    {
        return $this->fake;
    }

    protected function _prepareQuery()
    {
        $this->preparedQueries[] = $this->_query;

        $stmt = new FakeStatement($this->_query);
        $stmt->affected_rows = $this->nextAffectedRows;
        $stmt->insert_id = $this->nextInsertId;
        $stmt->executeResult = $this->nextExecuteResult;
        $stmt->throwOnExecute = $this->nextExecuteThrows;

        $this->statements[] = $stmt;

        return $stmt;
    }

    /**
     * The SQL string of the most recent prepared query.
     */
    public function lastPrepared()
    {
        if (empty($this->preparedQueries)) {
            return null;
        }

        return end($this->preparedQueries);
    }

    /**
     * The most recent stub statement.
     */
    public function lastStatement()
    {
        if (empty($this->statements)) {
            return null;
        }

        return end($this->statements);
    }

    /**
     * Values bound to the most recent statement.
     */
    public function lastBoundValues()
    {
        $stmt = $this->lastStatement();

        return $stmt === null ? array() : $stmt->boundValues;
    }

    /**
     * mysqli type string bound to the most recent statement.
     */
    public function lastBoundTypes()
    {
        $stmt = $this->lastStatement();

        return $stmt === null ? '' : $stmt->boundTypes;
    }

    /**
     * Build a query without executing it and return the SQL plus bind parameters.
     *
     * Used for assertions that only care about what the builder emits.
     *
     * @param int|array $numRows
     * @param array     $tableData
     *
     * @return array{sql: string, types: string, values: array}
     */
    public function buildOnly($numRows = null, $tableData = null)
    {
        $this->_buildQuery($numRows, $tableData);

        $params = $this->_bindParams;
        $types = array_shift($params);

        return array(
            'sql' => $this->_query,
            'types' => $types,
            'values' => array_values($params),
        );
    }

    /**
     * Seed the query prefix the way get()/update()/delete() would.
     */
    public function startQuery($sql)
    {
        $this->_query = $sql;

        return $this;
    }

    /**
     * Collapse runs of whitespace so assertions do not depend on the builder's spacing.
     */
    public static function normalize($sql)
    {
        return trim(preg_replace('/\s+/', ' ', (string) $sql));
    }
}
