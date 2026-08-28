<?php

/**
 * INSERT / REPLACE / UPDATE / DELETE statement construction and result handling.
 */
class InsertUpdateDeleteTest extends BuilderTestCase
{
    public function testInsertBuildsColumnAndValueLists()
    {
        $this->db->nextInsertId = 42;
        $id = $this->db->insert('users', array('login' => 'demo', 'age' => 30));

        $this->assertSqlEquals(
            'INSERT  INTO users (`login`, `age`)  VALUES (?, ?)',
            $this->db->lastPrepared()
        );
        $this->assertSame(array('demo', 30), $this->db->lastBoundValues());
        $this->assertSame('si', $this->db->lastBoundTypes());
        $this->assertSame(42, $id);
    }

    public function testInsertAppliesTablePrefix()
    {
        MysqliDb::$prefix = 't_';
        $this->db->insert('users', array('login' => 'demo'));

        $this->assertSqlContains('INTO t_users', $this->db->lastPrepared());
    }

    public function testInsertReturnsTrueWhenThereIsNoAutoIncrementId()
    {
        $this->db->nextInsertId = 0;
        $this->db->nextAffectedRows = 1;

        $this->assertTrue($this->db->insert('users', array('id' => 1)));
    }

    public function testInsertReturnsFalseWhenNoRowsWereAffected()
    {
        $this->db->nextAffectedRows = 0;

        $this->assertFalse($this->db->insert('users', array('login' => 'demo')));
    }

    public function testReplaceUsesReplaceKeyword()
    {
        $this->db->replace('users', array('login' => 'demo'));

        $this->assertStringStartsWith('REPLACE', $this->db->lastPrepared());
    }

    public function testInsertWithSqlFunction()
    {
        $this->db->insert('users', array(
            'login' => 'demo',
            'password' => $this->db->func('SHA1(?)', array('secret')),
            'createdAt' => $this->db->now(),
        ));

        $sql = $this->db->lastPrepared();
        $this->assertSqlContains('VALUES (?, SHA1(?), NOW())', $sql);
        $this->assertSame(array('demo', 'secret'), $this->db->lastBoundValues());
    }

    public function testUpdateWithIncrementFunction()
    {
        $this->db->where('id', 1)->update('users', array('loginCount' => $this->db->inc(2)));

        $this->assertSqlEquals(
            'UPDATE users SET `loginCount` = loginCount+2 WHERE id = ?',
            $this->db->lastPrepared()
        );
    }

    public function testUpdateWithNegationFunction()
    {
        $this->db->where('id', 1)->update('users', array('active' => $this->db->not()));

        $this->assertSqlContains('`active` = !active', $this->db->lastPrepared());
    }

    public function testUpdateBuildsSetClauseAndWhere()
    {
        $this->db->where('id', 7)->update('users', array('login' => 'demo', 'age' => 31));

        $this->assertSqlEquals(
            'UPDATE users SET `login` = ?, `age` = ? WHERE id = ?',
            $this->db->lastPrepared()
        );
        $this->assertSame(array('demo', 31, 7), $this->db->lastBoundValues());
    }

    public function testUpdateWithRowLimit()
    {
        $this->db->where('active', 1)->update('users', array('login' => 'demo'), 5);

        $this->assertSqlContains('LIMIT 5', $this->db->lastPrepared());
    }

    public function testOnDuplicateKeyUpdate()
    {
        $this->db->onDuplicate(array('loginCount'), 'id')
            ->insert('users', array('login' => 'demo', 'loginCount' => 1));

        $sql = $this->db->lastPrepared();
        $this->assertSqlContains('ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID (id), `loginCount` = ?', $sql);
    }

    public function testDeleteBuildsStatementWithWhere()
    {
        $this->db->where('id', 1)->delete('users');

        $this->assertSqlEquals('DELETE FROM users WHERE id = ?', $this->db->lastPrepared());
        $this->assertSame(array(1), $this->db->lastBoundValues());
    }

    public function testDeleteWithLimit()
    {
        $this->db->where('active', 0)->delete('users', 10);

        $this->assertSqlContains('LIMIT 10', $this->db->lastPrepared());
    }

    public function testDeleteReturnsTrueOnSuccess()
    {
        $this->db->nextAffectedRows = 3;

        $this->assertTrue($this->db->where('id', 1)->delete('users'));
        $this->assertSame(3, $this->db->count);
    }

    /**
     * Regression: the failure message interpolated $this->_stmtError before it was
     * assigned, so it always reported the previous query's error (usually empty).
     */
    public function testDeleteFailureReportsItsOwnError()
    {
        $this->db->nextExecuteThrows = 'Unknown column x';

        try {
            $this->db->where('id', 1)->delete('users');
            $this->fail('Expected delete() to throw');
        } catch (Exception $e) {
            $this->assertStringContainsString('Unknown column x', $e->getMessage());
        }
    }

    /**
     * Regression: throwing skipped reset(), so the WHERE clause of the failed delete
     * leaked into whatever query ran next on the same instance.
     */
    public function testDeleteFailureStillResetsBuilderState()
    {
        $this->db->nextExecuteThrows = 'boom';

        try {
            $this->db->where('id', 1)->delete('users');
        } catch (Exception $e) {
            // expected
        }

        $this->db->nextExecuteThrows = null;
        $this->db->startQuery('SELECT * FROM products');
        $built = $this->db->buildOnly();

        $this->assertSqlEquals('SELECT * FROM products', $built['sql']);
        $this->assertSame(array(), $built['values']);
    }

    /**
     * Regression: statements were never closed on write paths, which exhausts
     * max_prepared_stmt_count on long running processes.
     */
    public function testWriteStatementsAreClosed()
    {
        $this->db->insert('users', array('login' => 'demo'));
        $this->assertTrue($this->db->lastStatement()->closed, 'insert() should close its statement');

        $this->db->where('id', 1)->update('users', array('login' => 'demo'));
        $this->assertTrue($this->db->lastStatement()->closed, 'update() should close its statement');

        $this->db->where('id', 1)->delete('users');
        $this->assertTrue($this->db->lastStatement()->closed, 'delete() should close its statement');
    }

    /**
     * Regression: _buildDataPairs() read $tableData[$column] blindly, producing an
     * "undefined array key" warning instead of a usable error.
     */
    public function testMissingColumnValueRaisesAClearError()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("No value given for column 'missing'");

        $this->db->_buildDataPairs(array('login' => 'demo'), array('login', 'missing'), true);
    }

    public function testUnknownFunctionOperationIsRejected()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Wrong operation');

        $this->db->insert('users', array('login' => array('[X]' => 'nope')));
    }
}
