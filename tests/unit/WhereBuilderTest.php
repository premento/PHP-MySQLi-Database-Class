<?php

/**
 * WHERE / HAVING clause construction.
 */
class WhereBuilderTest extends BuilderTestCase
{
    public function testSimpleEquality()
    {
        $built = $this->db->where('id', 1)->startQuery('SELECT * FROM users')->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id = ?', $built['sql']);
        $this->assertSame(array(1), $built['values']);
        $this->assertSame('i', $built['types']);
    }

    public function testMultipleConditionsAreAndedTogether()
    {
        $built = $this->db->where('id', 1)
            ->where('login', 'admin')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id = ? AND login = ?', $built['sql']);
        $this->assertSame(array(1, 'admin'), $built['values']);
        $this->assertSame('is', $built['types']);
    }

    public function testOrWhere()
    {
        $built = $this->db->where('id', 1)
            ->orWhere('id', 2)
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id = ? OR id = ?', $built['sql']);
        $this->assertSame(array(1, 2), $built['values']);
    }

    public function testExplicitOperator()
    {
        $built = $this->db->where('id', 50, '>=')->startQuery('SELECT * FROM users')->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id >= ?', $built['sql']);
        $this->assertSame(array(50), $built['values']);
    }

    /**
     * Regression: the documented operator-as-key form, where('id', Array('>=' => 50)),
     * bound its value but emitted no operator or placeholder, producing "WHERE id".
     */
    public function testOperatorAsArrayKeyIsSupported()
    {
        $built = $this->db->where('id', array('>=' => 50))->startQuery('SELECT * FROM users')->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id >= ?', $built['sql']);
        $this->assertSame(array(50), $built['values']);
    }

    public function testInOperatorAsArrayKeyIsSupported()
    {
        $built = $this->db->where('id', array('IN' => array(1, 5, 27)))
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id IN ( ?, ?, ? )', $built['sql']);
        $this->assertSame(array(1, 5, 27), $built['values']);
    }

    public function testBetweenOperatorAsArrayKeyIsSupported()
    {
        $built = $this->db->where('id', array('BETWEEN' => array(4, 20)))
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id BETWEEN ? AND ?', $built['sql']);
        $this->assertSame(array(4, 20), $built['values']);
    }

    public function testInOperator()
    {
        $built = $this->db->where('id', array(1, 5, 27), 'IN')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id IN ( ?, ?, ? )', $built['sql']);
        $this->assertSame(array(1, 5, 27), $built['values']);
    }

    public function testNotInOperator()
    {
        $built = $this->db->where('id', array(1, 2), 'NOT IN')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlContains('id NOT IN ( ?, ? )', $built['sql']);
    }

    public function testBetweenOperator()
    {
        $built = $this->db->where('id', array(4, 20), 'BETWEEN')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id BETWEEN ? AND ?', $built['sql']);
        $this->assertSame(array(4, 20), $built['values']);
    }

    /**
     * A numerically indexed array supplies bind values for a condition that already
     * carries its own placeholders. This must not be confused with the operator-as-key
     * form handled above.
     */
    public function testRawConditionWithItsOwnPlaceholders()
    {
        $built = $this->db->where('(id = ? or id = ?)', array(6, 2))
            ->where('login', 'mike')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE (id = ? or id = ?) AND login = ?', $built['sql']);
        $this->assertSame(array(6, 2, 'mike'), $built['values']);
    }

    public function testNullComparison()
    {
        $built = $this->db->where('lastName', null, 'IS NOT')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE lastName IS NOT NULL', $built['sql']);
        $this->assertSame(array(), $built['values']);
    }

    public function testConditionWithoutAValueIsEmittedVerbatim()
    {
        $built = $this->db->where('id != companyId')->startQuery('SELECT * FROM users')->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE id != companyId', $built['sql']);
        $this->assertSame(array(), $built['values']);
    }

    /**
     * Zero is a legitimate value; it must not be mistaken for the DBNULL sentinel.
     */
    public function testZeroIsBoundRatherThanTreatedAsAbsent()
    {
        $built = $this->db->where('active', 0)->startQuery('SELECT * FROM users')->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE active = ?', $built['sql']);
        $this->assertSame(array(0), $built['values']);
    }

    public function testEmptyStringIsBound()
    {
        $built = $this->db->where('login', '')->startQuery('SELECT * FROM users')->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users WHERE login = ?', $built['sql']);
        $this->assertSame(array(''), $built['values']);
    }

    public function testBooleanIsBoundAsInteger()
    {
        $built = $this->db->where('active', true)->startQuery('SELECT * FROM users')->buildOnly();

        $this->assertSame('i', $built['types']);
        $this->assertSame(array(true), $built['values']);
    }

    public function testHavingClause()
    {
        $built = $this->db->groupBy('customerId')
            ->having('total', 10, '>')
            ->startQuery('SELECT customerId, SUM(qty) total FROM orders')
            ->buildOnly();

        $this->assertSqlContains('GROUP BY customerId', $built['sql']);
        $this->assertSqlContains('HAVING total > ?', $built['sql']);
        $this->assertSame(array(10), $built['values']);
    }

    /**
     * Regression: orHaving() defaulted to $operator = null, so orHaving('x', 5) built
     * "OR x ?" with no comparison operator at all.
     */
    public function testOrHavingDefaultsMatchHaving()
    {
        $built = $this->db->having('total', 10, '>')
            ->orHaving('total', 5)
            ->startQuery('SELECT customerId FROM orders')
            ->buildOnly();

        $this->assertSqlContains('HAVING total > ? OR total = ?', $built['sql']);
        $this->assertSame(array(10, 5), $built['values']);
    }

    public function testOrHavingWithNullValueEmitsIsNull()
    {
        $built = $this->db->having('total', 10, '>')
            ->orHaving('total', null, 'IS')
            ->startQuery('SELECT customerId FROM orders')
            ->buildOnly();

        $this->assertSqlContains('OR total IS NULL', $built['sql']);
    }

    public function testWhereAndHavingCoexist()
    {
        $built = $this->db->where('active', 1)
            ->having('total', 10, '>')
            ->startQuery('SELECT customerId FROM orders')
            ->buildOnly();

        $this->assertSqlContains('WHERE active = ?', $built['sql']);
        $this->assertSqlContains('HAVING total > ?', $built['sql']);
    }
}
