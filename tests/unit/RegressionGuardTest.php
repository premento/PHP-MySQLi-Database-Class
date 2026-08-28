<?php

/**
 * Guards for behaviours that are easy to reintroduce, and for the documented examples
 * in readme.md that had drifted away from what the code actually did.
 */
class RegressionGuardTest extends BuilderTestCase
{
    /**
     * readme.md: "SELECT * FROM users WHERE id >= 50"
     */
    public function testReadmeOperatorExamples()
    {
        $built = $this->db->where('id', 50, '>=')->startQuery('SELECT * FROM users')->buildOnly();
        $this->assertSqlEquals('SELECT * FROM users WHERE id >= ?', $built['sql']);

        $db = new TestableMysqliDb('h', 'u', 'p', 'd');
        $built = $db->where('id', array('>=' => 50))->startQuery('SELECT * FROM users')->buildOnly();
        $this->assertSqlEquals('SELECT * FROM users WHERE id >= ?', $built['sql']);
    }

    /**
     * readme.md documents both spellings of BETWEEN and IN.
     */
    public function testReadmeBetweenAndInExamplesAgree()
    {
        $a = (new TestableMysqliDb('h', 'u', 'p', 'd'))
            ->where('id', array(4, 20), 'BETWEEN')
            ->startQuery('SELECT * FROM users')->buildOnly();

        $b = (new TestableMysqliDb('h', 'u', 'p', 'd'))
            ->where('id', array('BETWEEN' => array(4, 20)))
            ->startQuery('SELECT * FROM users')->buildOnly();

        $this->assertSame(
            TestableMysqliDb::normalize($a['sql']),
            TestableMysqliDb::normalize($b['sql'])
        );
        $this->assertSame($a['values'], $b['values']);

        $c = (new TestableMysqliDb('h', 'u', 'p', 'd'))
            ->where('id', array(1, 5, 27), 'IN')
            ->startQuery('SELECT * FROM users')->buildOnly();

        $d = (new TestableMysqliDb('h', 'u', 'p', 'd'))
            ->where('id', array('IN' => array(1, 5, 27)))
            ->startQuery('SELECT * FROM users')->buildOnly();

        $this->assertSame(
            TestableMysqliDb::normalize($c['sql']),
            TestableMysqliDb::normalize($d['sql'])
        );
        $this->assertSame($c['values'], $d['values']);
    }

    /**
     * A statement error must never survive into an unrelated later query.
     */
    public function testSuccessfulQueryClearsThePreviousError()
    {
        $this->db->nextExecuteThrows = 'boom';
        try {
            $this->db->where('id', 1)->update('users', array('a' => 1));
        } catch (Exception $e) {
            // update() does not throw, but guard anyway
        }
        $this->assertSame('boom', $this->db->getLastError());

        $this->db->nextExecuteThrows = null;
        $this->db->where('id', 1)->update('users', array('a' => 1));

        $this->assertSame('', $this->db->getLastError());
    }

    /**
     * reset() must clear every part of the builder, so consecutive queries on one
     * instance cannot contaminate each other.
     */
    public function testBuilderStateDoesNotLeakBetweenQueries()
    {
        $this->db->where('id', 1)
            ->join('products p', 'p.userId = u.id', 'LEFT')
            ->orderBy('id', 'ASC')
            ->groupBy('customerId')
            ->having('total', 1, '>')
            ->get('users u');

        $this->db->get('products');

        $this->assertSqlEquals('SELECT  * FROM products', $this->db->lastPrepared());
        $this->assertSame(array(), $this->db->lastBoundValues());
    }

    public function testReturnTypeResetsToArrayAfterAQuery()
    {
        $this->db->ObjectBuilder()->get('users');

        $this->assertSame('array', $this->db->returnType);
    }

    /**
     * _buildInsertQuery() must not mistake a SELECT for an INSERT. The old character
     * class regex matched any query starting with I, N, S, E, R, T, P, L, A or C.
     */
    public function testSelectIsNotTreatedAsAnInsert()
    {
        $method = new ReflectionMethod(MysqliDb::class, '_buildInsertQuery');
        $method->setAccessible(true);

        $this->db->startQuery('SELECT * FROM users');
        $method->invoke($this->db, array('login' => 'demo'));

        $property = new ReflectionProperty(MysqliDb::class, '_query');
        $property->setAccessible(true);

        $this->assertStringContainsString('SET', $property->getValue($this->db));
        $this->assertStringNotContainsString('VALUES', $property->getValue($this->db));
    }

    public function testInsertIsStillDetectedWithLeadingWhitespace()
    {
        $method = new ReflectionMethod(MysqliDb::class, '_buildInsertQuery');
        $method->setAccessible(true);

        $this->db->startQuery('  INSERT INTO users');
        $method->invoke($this->db, array('login' => 'demo'));

        $property = new ReflectionProperty(MysqliDb::class, '_query');
        $property->setAccessible(true);

        $this->assertStringContainsString('VALUES', $property->getValue($this->db));
    }

    /**
     * The 'DBNULL' sentinel marks "no value was passed"; a real value must never be
     * mistaken for it, and 0 in particular must still be bound.
     */
    public function testDbNullSentinelHandling()
    {
        $built = $this->db->where('raw condition')->startQuery('SELECT * FROM users')->buildOnly();
        $this->assertSqlEquals('SELECT * FROM users WHERE raw condition', $built['sql']);
        $this->assertSame(array(), $built['values']);

        $db = new TestableMysqliDb('h', 'u', 'p', 'd');
        $built = $db->where('n', 0)->startQuery('SELECT * FROM users')->buildOnly();
        $this->assertSame(array(0), $built['values']);
    }

    public function testTableExistsBuildsAnInformationSchemaQuery()
    {
        $this->db->tableExists(array('users', 'products'));

        $sql = $this->db->lastPrepared();
        $this->assertSqlContains('FROM information_schema.tables', $sql);
        // The operator is emitted with the caller's own casing.
        $this->assertSqlContains('table_name in ( ?, ? )', $sql);
    }

    public function testGetOneAddsLimitOne()
    {
        $this->db->getOne('users');

        $this->assertSqlContains('LIMIT 1', $this->db->lastPrepared());
    }

    public function testGetWithExplicitColumnsAsArray()
    {
        $this->db->get('users', null, array('id', 'login'));

        $this->assertSqlEquals('SELECT  id, login FROM users', $this->db->lastPrepared());
    }

    public function testGetWithEmptyColumnsFallsBackToStar()
    {
        $this->db->get('users', null, '');

        $this->assertSqlContains('SELECT * FROM users', $this->db->lastPrepared());
    }

    public function testDottedTableNameSkipsThePrefix()
    {
        MysqliDb::$prefix = 't_';
        $this->db->get('otherdb.users');

        $this->assertSqlContains('FROM otherdb.users', $this->db->lastPrepared());
        $this->assertStringNotContainsString('t_otherdb', $this->db->lastPrepared());
    }

    public function testQueryOptionsAreEmittedAfterSelect()
    {
        $this->db->setQueryOption('DISTINCT')->get('users');

        $this->assertSqlContains('SELECT DISTINCT * FROM users', $this->db->lastPrepared());
    }

    public function testWithTotalCountAddsSqlCalcFoundRows()
    {
        $this->db->withTotalCount()->get('users');

        $this->assertSqlContains('SELECT SQL_CALC_FOUND_ROWS * FROM users', $this->db->lastPrepared());
    }
}
