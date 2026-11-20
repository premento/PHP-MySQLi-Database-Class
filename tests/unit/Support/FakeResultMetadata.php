<?php

/**
 * Stand-in for the mysqli_result returned by mysqli_stmt::result_metadata().
 *
 * MysqliDb only walks it with fetch_field() and reads name, table and type off each
 * field, so that is all this models.
 */
class FakeResultMetadata
{
    /** @var stdClass[] */
    private $fields = array();

    /** @var int */
    private $cursor = 0;

    /**
     * @param string[] $columns Column names, in result-set order.
     * @param string   $table   Table name reported for every column.
     */
    public function __construct(array $columns, $table = '')
    {
        foreach ($columns as $name) {
            $field = new stdClass();
            $field->name = $name;
            $field->table = $table;
            // MYSQLI_TYPE_VAR_STRING. Anything other than 252 (the LONG/BLOB family) keeps
            // MysqliDb off the store_result() path, which is not what these tests are about.
            $field->type = 253;

            $this->fields[] = $field;
        }
    }

    /**
     * @return stdClass|false
     */
    public function fetch_field()
    {
        if ($this->cursor >= count($this->fields)) {
            return false;
        }

        return $this->fields[$this->cursor++];
    }

    public function free_result()
    {
        return true;
    }
}
