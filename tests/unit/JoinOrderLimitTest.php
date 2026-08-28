<?php

/**
 * JOIN, ORDER BY, GROUP BY and LIMIT construction.
 */
class JoinOrderLimitTest extends BuilderTestCase
{
    public function testInnerJoin()
    {
        $built = $this->db->join('products p', 'p.userId = u.id', 'LEFT')
            ->startQuery('SELECT * FROM users u')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users u LEFT JOIN products p on p.userId = u.id', $built['sql']);
    }

    public function testJoinAppliesTablePrefix()
    {
        MysqliDb::$prefix = 't_';

        $built = $this->db->join('products p', 'p.userId = u.id', 'LEFT')
            ->startQuery('SELECT * FROM t_users u')
            ->buildOnly();

        $this->assertSqlContains('LEFT JOIN t_products p', $built['sql']);
    }

    public function testJoinWithUsingClauseOmitsOn()
    {
        $built = $this->db->join('products', 'USING (userId)', 'INNER')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlContains('INNER JOIN products USING (userId)', $built['sql']);
        $this->assertStringNotContainsString(' on USING', $built['sql']);
    }

    public function testInvalidJoinTypeIsRejected()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Wrong JOIN type');

        $this->db->join('products', 'x = y', 'SIDEWAYS');
    }

    public function testJoinWhereAddsConditionToTheOnClause()
    {
        $built = $this->db->join('products p', 'p.userId = u.id', 'LEFT')
            ->joinWhere('products p', 'p.active', 1)
            ->startQuery('SELECT * FROM users u')
            ->buildOnly();

        $this->assertSqlContains('LEFT JOIN products p on p.userId = u.id AND p.active = ?', $built['sql']);
        $this->assertSame(array(1), $built['values']);
    }

    /**
     * Regression: conditionToSql() emitted the NULL comparison without a leading space,
     * producing "p.deletedAtIS NULL" for join conditions.
     */
    public function testJoinWhereWithNullValueKeepsSpacing()
    {
        $built = $this->db->join('products p', 'p.userId = u.id', 'LEFT')
            ->joinWhere('products p', 'p.deletedAt', null, 'IS')
            ->startQuery('SELECT * FROM users u')
            ->buildOnly();

        $this->assertSqlContains('p.deletedAt IS NULL', $built['sql']);
        $this->assertStringNotContainsString('deletedAtIS', $built['sql']);
    }

    public function testJoinOrWhere()
    {
        $built = $this->db->join('products p', 'p.userId = u.id', 'LEFT')
            ->joinWhere('products p', 'p.active', 1)
            ->joinOrWhere('products p', 'p.featured', 1)
            ->startQuery('SELECT * FROM users u')
            ->buildOnly();

        $this->assertSqlContains('AND p.active = ? OR p.featured = ?', $built['sql']);
    }

    public function testOrderBy()
    {
        $built = $this->db->orderBy('id', 'ASC')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlEquals('SELECT * FROM users ORDER BY id ASC', $built['sql']);
    }

    public function testOrderByRandDoesNotGetADirection()
    {
        $built = $this->db->orderBy('rand()')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlContains('ORDER BY rand()', $built['sql']);
    }

    public function testInvalidOrderDirectionIsRejected()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Wrong order direction');

        $this->db->orderBy('id', 'SIDEWAYS');
    }

    public function testOrderByField()
    {
        $built = $this->db->orderBy('userGroup', 'ASC', array('superuser', 'admin', 'users'))
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlContains('ORDER BY FIELD (userGroup, "superuser","admin","users") ASC', $built['sql']);
    }

    /**
     * Regression: the REGEXP branch concatenated the caller's pattern straight into the
     * query, so a quote in the pattern terminated the string literal and injected SQL.
     */
    public function testOrderByRegexpIsEscaped()
    {
        $built = $this->db->orderBy('login', 'ASC', "a' OR 1=1 -- ")
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlContains("REGEXP 'a\\' OR 1=1 -- '", $built['sql']);
    }

    public function testOrderByRejectsNonStringNonArrayCustomField()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Wrong custom field or Regular Expression');

        $this->db->orderBy('login', 'ASC', 12.5);
    }

    public function testGroupBy()
    {
        $built = $this->db->groupBy('customerId')
            ->startQuery('SELECT customerId FROM orders')
            ->buildOnly();

        $this->assertSqlEquals('SELECT customerId FROM orders GROUP BY customerId', $built['sql']);
    }

    public function testMultipleGroupByColumns()
    {
        $built = $this->db->groupBy('customerId')
            ->groupBy('productId')
            ->startQuery('SELECT customerId FROM orders')
            ->buildOnly();

        $this->assertSqlContains('GROUP BY customerId, productId', $built['sql']);
    }

    public function testLimitAsScalar()
    {
        $built = $this->db->startQuery('SELECT * FROM users')->buildOnly(10);

        $this->assertSqlContains('LIMIT 10', $built['sql']);
    }

    public function testLimitAsOffsetAndCount()
    {
        $built = $this->db->startQuery('SELECT * FROM users')->buildOnly(array(20, 10));

        $this->assertSqlContains('LIMIT 20, 10', $built['sql']);
    }

    public function testLimitIsCastToIntegerSoItCannotCarrySql()
    {
        $built = $this->db->startQuery('SELECT * FROM users')->buildOnly('10; DROP TABLE users');

        $this->assertSqlContains('LIMIT 10', $built['sql']);
        $this->assertStringNotContainsString('DROP', $built['sql']);
    }

    public function testForUpdateAndLockInShareMode()
    {
        $built = $this->db->setQueryOption('FOR UPDATE')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlContains('FOR UPDATE', $built['sql']);

        $db = new TestableMysqliDb('localhost', 'u', 'p', 'd');
        $built = $db->setQueryOption('LOCK IN SHARE MODE')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertSqlContains('LOCK IN SHARE MODE', $built['sql']);
    }

    public function testInvalidQueryOptionIsRejected()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Wrong query option');

        $this->db->setQueryOption('DROP DATABASE');
    }
}
