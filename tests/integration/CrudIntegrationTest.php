<?php

/**
 * End-to-end coverage against a real server. These exercise the parts the unit suite
 * cannot: real prepared statements, real type binding and real transaction semantics.
 */
class CrudIntegrationTest extends IntegrationTestCase
{
    public function testInsertAndGet()
    {
        $this->createUsersTable();

        $id = $this->db->insert('users', array('login' => 'demo', 'customerId' => 10));
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $rows = $this->db->where('id', $id)->get('users');

        $this->assertCount(1, $rows);
        $this->assertSame('demo', $rows[0]['login']);
    }

    public function testGetOneReturnsASingleRow()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'demo'));

        $row = $this->db->where('login', 'demo')->getOne('users');

        $this->assertIsArray($row);
        $this->assertSame('demo', $row['login']);
    }

    public function testGetValue()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'demo'));

        $this->assertSame('demo', $this->db->where('login', 'demo')->getValue('users', 'login'));
    }

    public function testUpdate()
    {
        $this->createUsersTable();
        $id = $this->db->insert('users', array('login' => 'demo', 'customerId' => 1));

        $this->assertTrue($this->db->where('id', $id)->update('users', array('customerId' => 2)));
        $this->assertSame(1, $this->db->count);

        $row = $this->db->where('id', $id)->getOne('users');
        $this->assertSame(2, (int) $row['customerId']);
    }

    public function testDeleteReturnsTrueAndRemovesTheRow()
    {
        $this->createUsersTable();
        $id = $this->db->insert('users', array('login' => 'demo'));

        $this->assertTrue($this->db->where('id', $id)->delete('users'));
        $this->assertNull($this->db->where('id', $id)->getOne('users'));
    }

    /**
     * Regression: delete() reported a stale error message and skipped reset(), leaking
     * the failed query's WHERE clause into the next statement.
     */
    public function testFailedDeleteThrowsAndDoesNotLeakState()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'demo'));

        try {
            $this->db->where('no_such_column', 1)->delete('users');
            $this->fail('Expected delete() to throw for an unknown column');
        } catch (Exception $e) {
            $this->assertNotSame('Failed to execute delete operation: ', $e->getMessage());
            $this->assertStringContainsString('no_such_column', $e->getMessage());
        }

        // The next query must be unaffected by the failed one.
        $rows = $this->db->get('users');
        $this->assertCount(1, $rows);
    }

    /**
     * Regression: the recorded error survived into later successful queries.
     */
    public function testErrorStateIsClearedByTheNextQuery()
    {
        $this->createUsersTable();

        try {
            $this->db->where('no_such_column', 1)->delete('users');
        } catch (Exception $e) {
            // expected
        }

        $this->db->insert('users', array('login' => 'demo'));

        $this->assertSame('', $this->db->getLastError());
        $this->assertSame(0, $this->db->getLastErrno());
    }

    public function testWhereInWithScalarList()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'a'));
        $this->db->insert('users', array('login' => 'b'));
        $this->db->insert('users', array('login' => 'c'));

        $rows = $this->db->where('login', array('a', 'c'), 'IN')->get('users');

        $this->assertCount(2, $rows);
    }

    /**
     * Regression: a subquery in IN was wrapped in a second pair of parentheses, so MySQL
     * evaluated it as a scalar subquery and failed with "Subquery returns more than 1
     * row" as soon as it matched more than one.
     */
    public function testWhereInWithAMultiRowSubQuery()
    {
        $this->createUsersTable();
        $this->createTable('orders', 'id INT AUTO_INCREMENT PRIMARY KEY, userId INT NOT NULL, qty INT NOT NULL');

        $a = $this->db->insert('users', array('login' => 'a'));
        $b = $this->db->insert('users', array('login' => 'b'));
        $this->db->insert('users', array('login' => 'c'));

        $this->db->insert('orders', array('userId' => $a, 'qty' => 5));
        $this->db->insert('orders', array('userId' => $b, 'qty' => 9));

        $sub = MysqliDb::subQuery();
        $sub->where('qty', 2, '>');
        $sub->get('orders', null, 'userId');

        $rows = $this->db->where('id', $sub, 'in')->get('users');

        $this->assertCount(2, $rows, 'a multi-row subquery must be usable with IN');
    }

    public function testExistsSubQuery()
    {
        $this->createUsersTable();
        $this->createTable('orders', 'id INT AUTO_INCREMENT PRIMARY KEY, userId INT NOT NULL');

        $a = $this->db->insert('users', array('login' => 'a'));
        $this->db->insert('orders', array('userId' => $a));

        $sub = MysqliDb::subQuery();
        $sub->where('userId', $a);
        $sub->get('orders', null, 'userId');

        $rows = $this->db->where(null, $sub, 'exists')->get('users');

        $this->assertNotEmpty($rows);
    }

    /**
     * Regression: the documented operator-as-key form silently produced invalid SQL.
     */
    public function testOperatorAsArrayKey()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'a', 'customerId' => 10));
        $this->db->insert('users', array('login' => 'b', 'customerId' => 60));

        $rows = $this->db->where('customerId', array('>=' => 50))->get('users');

        $this->assertCount(1, $rows);
        $this->assertSame('b', $rows[0]['login']);
    }

    public function testOrderByRegexp()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'abc'));
        $this->db->insert('users', array('login' => 'xyz'));

        $rows = $this->db->orderBy('login', 'DESC', '^a')->get('users');

        $this->assertCount(2, $rows);
    }

    /**
     * Regression: the REGEXP pattern was inlined without escaping.
     */
    public function testOrderByRegexpWithAQuoteDoesNotBreakTheQuery()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'abc'));

        $rows = $this->db->orderBy('login', 'DESC', "a'b")->get('users');

        $this->assertCount(1, $rows);
    }

    public function testTransactionRollback()
    {
        $this->createUsersTable();

        $this->db->startTransaction();
        $this->db->insert('users', array('login' => 'temp'));
        $this->db->rollback();

        $this->assertSame(0, count($this->db->get('users')));
    }

    public function testTransactionCommit()
    {
        $this->createUsersTable();

        $this->db->startTransaction();
        $this->db->insert('users', array('login' => 'kept'));
        $this->db->commit();

        $this->assertCount(1, $this->db->get('users'));
    }

    public function testInsertMultiRollsBackEveryRowOnFailure()
    {
        $this->createUsersTable();

        // The second row duplicates the first on the UNIQUE login key.
        $result = $this->db->insertMulti('users', array(
            array('login' => 'dup'),
            array('login' => 'dup'),
        ));

        $this->assertFalse($result);
        $this->assertSame(0, count($this->db->get('users')), 'the first insert must be rolled back too');
    }

    public function testOnDuplicateKeyUpdate()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'demo', 'customerId' => 1));

        $this->db->onDuplicate(array('customerId'), 'id')
            ->insert('users', array('login' => 'demo', 'customerId' => 99));

        $row = $this->db->where('login', 'demo')->getOne('users');

        $this->assertSame(99, (int) $row['customerId']);
        $this->assertCount(1, $this->db->get('users'));
    }

    public function testPaginate()
    {
        $this->createUsersTable();
        for ($i = 0; $i < 5; $i++) {
            $this->db->insert('users', array('login' => 'user' . $i));
        }

        $this->db->pageLimit = 2;
        $page = $this->db->paginate('users', 2);

        $this->assertCount(2, $page);
        $this->assertSame(5, (int) $this->db->totalCount);
        $this->assertSame(3, $this->db->totalPages);
    }

    public function testJoin()
    {
        $this->createUsersTable();
        $this->createTable('orders', 'id INT AUTO_INCREMENT PRIMARY KEY, userId INT NOT NULL, qty INT NOT NULL');

        $a = $this->db->insert('users', array('login' => 'a'));
        $this->db->insert('orders', array('userId' => $a, 'qty' => 3));

        $rows = $this->db->join('orders o', 'o.userId = u.id', 'LEFT')
            ->where('u.id', $a)
            ->get('users u', null, 'u.login, o.qty');

        $this->assertCount(1, $rows);
        $this->assertSame('a', $rows[0]['login']);
        $this->assertSame(3, (int) $rows[0]['qty']);
    }

    public function testNullValuesRoundTrip()
    {
        $this->createUsersTable();
        $id = $this->db->insert('users', array('login' => 'demo', 'customerId' => null));

        $row = $this->db->where('id', $id)->getOne('users');
        $this->assertNull($row['customerId']);

        $rows = $this->db->where('customerId', null, 'IS')->get('users');
        $this->assertCount(1, $rows);
    }

    public function testFloatAndBooleanBinding()
    {
        $this->createUsersTable();
        $id = $this->db->insert('users', array('login' => 'demo', 'score' => 1.5, 'active' => true));

        $row = $this->db->where('id', $id)->getOne('users');

        $this->assertSame(1.5, (float) $row['score']);
        $this->assertSame(1, (int) $row['active']);
    }

    public function testHasReturnsTrueOnlyForMatchingRows()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'demo'));

        $this->assertTrue($this->db->where('login', 'demo')->has('users'));
        $this->assertFalse($this->db->where('login', 'nope')->has('users'));
    }

    public function testTableExists()
    {
        $this->createUsersTable();

        $this->assertTrue($this->db->tableExists('users'));
        $this->assertFalse($this->db->tableExists('definitely_not_here'));
    }

    /**
     * Regression: rawAddPrefix() rewrote table names inside string literals and treated
     * ON DUPLICATE KEY UPDATE as an UPDATE statement.
     */
    public function testRawQueryWithAStringLiteralContainingKeywords()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'demo', 'note' => 'update products now'));

        $rows = $this->db->rawQuery("SELECT * FROM users WHERE note = 'update products now'");

        $this->assertCount(1, $rows);
        $this->assertSame('demo', $rows[0]['login']);
    }

    public function testRawQueryAppliesThePrefixOnce()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'demo'));

        // Written without the prefix: rawAddPrefix() adds it.
        $unprefixed = $this->db->rawQuery('SELECT * FROM users');
        // Written with the prefix already: it must not be applied twice.
        $prefixed = $this->db->rawQuery('SELECT * FROM ' . $this->prefix . 'users');

        $this->assertCount(1, $unprefixed);
        $this->assertCount(1, $prefixed);
    }

    public function testRawQueryWithBoundParameters()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'demo', 'customerId' => 7));

        $rows = $this->db->rawQuery('SELECT * FROM users WHERE customerId = ?', array(7));

        $this->assertCount(1, $rows);
    }

    public function testLockAndUnlock()
    {
        $this->createUsersTable();

        $this->db->setLockMethod('WRITE');
        $this->assertTrue($this->db->lock('users'));
        $this->db->unlock();

        // Still usable afterwards.
        $this->db->insert('users', array('login' => 'demo'));
        $this->assertCount(1, $this->db->get('users'));
    }

    public function testMapKeyedResults()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'a', 'customerId' => 1));
        $this->db->insert('users', array('login' => 'b', 'customerId' => 2));

        $rows = $this->db->map('login')->get('users', null, 'login, customerId');

        $this->assertArrayHasKey('a', $rows);
        $this->assertArrayHasKey('b', $rows);
    }

    public function testObjectAndJsonReturnTypes()
    {
        $this->createUsersTable();
        $this->db->insert('users', array('login' => 'demo'));

        $object = $this->db->ObjectBuilder()->getOne('users');
        $this->assertIsObject($object);
        $this->assertSame('demo', $object->login);

        $json = $this->db->JsonBuilder()->get('users');
        $this->assertJson($json);
    }
}
