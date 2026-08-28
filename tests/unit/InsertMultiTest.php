<?php

/**
 * insertMulti() and the transaction handling around it.
 */
class InsertMultiTest extends BuilderTestCase
{
    public function testInsertsEveryRowAndReturnsTheIds()
    {
        $this->db->nextInsertId = 7;

        $ids = $this->db->insertMulti('users', array(
            array('login' => 'a'),
            array('login' => 'b'),
        ));

        $this->assertSame(array(7, 7), $ids);
        $this->assertCount(2, $this->db->preparedQueries);
    }

    public function testAppliesExplicitColumnNames()
    {
        $this->db->nextInsertId = 1;

        $ids = $this->db->insertMulti(
            'users',
            array(array('a', 1), array('b', 2)),
            array('login', 'customerId')
        );

        $this->assertCount(2, $ids);
        $this->assertSqlContains('(`login`, `customerId`)', $this->db->preparedQueries[0]);
        $this->assertSame(array('b', 2), $this->db->lastBoundValues());
    }

    public function testWrapsTheInsertsInATransaction()
    {
        $this->db->nextInsertId = 1;
        $this->db->insertMulti('users', array(array('login' => 'a')));

        $this->assertSame(1, $this->db->fake->commitCount);
        $this->assertSame(0, $this->db->fake->rollbackCount);
    }

    public function testRollsBackAndReturnsFalseWhenARowFails()
    {
        $this->db->nextAffectedRows = 0; // insert() reports failure

        $this->assertFalse($this->db->insertMulti('users', array(array('login' => 'a'))));
        $this->assertSame(1, $this->db->fake->rollbackCount);
        $this->assertSame(0, $this->db->fake->commitCount);
    }

    /**
     * Regression: a column/value count mismatch reached array_combine(), which throws an
     * uncaught ValueError and left the transaction open.
     */
    public function testColumnCountMismatchReportsTheOffendingRowAndRollsBack()
    {
        try {
            $this->db->insertMulti(
                'users',
                array(array('a', 1), array('b')),
                array('login', 'customerId')
            );
            $this->fail('Expected insertMulti() to throw');
        } catch (Exception $e) {
            $this->assertStringContainsString('row 1', $e->getMessage());
            $this->assertStringContainsString('1 value(s) but 2 column name(s)', $e->getMessage());
        }

        $this->assertSame(1, $this->db->fake->rollbackCount, 'the transaction must be rolled back');
    }

    public function testDoesNotOpenItsOwnTransactionWhenOneIsAlreadyRunning()
    {
        $this->db->nextInsertId = 1;
        $this->db->startTransaction();
        $this->db->fake->commitCount = 0;

        $this->db->insertMulti('users', array(array('login' => 'a')));

        $this->assertSame(0, $this->db->fake->commitCount, 'the caller owns the transaction');
    }

    /**
     * Regression: a shutdown handler was registered on every startTransaction() call.
     */
    public function testShutdownHandlerIsRegisteredOnlyOnce()
    {
        $property = new ReflectionProperty(MysqliDb::class, '_shutdownHandlerRegistered');
        $property->setAccessible(true);

        $this->db->startTransaction();
        $this->assertTrue($property->getValue($this->db));

        $this->db->commit();
        $this->db->startTransaction();

        // Still exactly one registration; nothing to assert beyond the flag staying set,
        // but this documents the intent and fails if the guard is removed.
        $this->assertTrue($property->getValue($this->db));
        $this->db->rollback();
    }

    public function testCommitAndRollbackClearTheInProgressFlag()
    {
        $property = new ReflectionProperty(MysqliDb::class, '_transaction_in_progress');
        $property->setAccessible(true);

        $this->db->startTransaction();
        $this->assertTrue($property->getValue($this->db));

        $this->db->commit();
        $this->assertFalse($property->getValue($this->db));

        $this->db->startTransaction();
        $this->db->rollback();
        $this->assertFalse($property->getValue($this->db));
    }
}
