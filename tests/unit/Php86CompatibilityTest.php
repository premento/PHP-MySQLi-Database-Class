<?php

/**
 * Guards for constructs PHP deprecated between 8.4 and 8.6.
 *
 * phpunit.xml runs with failOnDeprecation="true", so any of these emitting an
 * E_DEPRECATED fails the suite rather than quietly passing.
 */
class Php86CompatibilityTest extends BuilderTestCase
{
    /**
     * mysqli::ping() is deprecated as of PHP 8.4 (reconnect, the only reason it existed,
     * was dropped in 8.2). ping() must therefore probe the connection with a real query.
     */
    public function testPingProbesWithAQueryInsteadOfMysqliPing()
    {
        $this->assertTrue($this->db->ping());

        $this->assertSame(
            array('DO 1'),
            $this->db->fake->unpreparedQueries,
            'ping() should probe the server with one cheap unprepared query.'
        );
    }

    /**
     * The probe has to report a dead connection, not crash on it. Covers the legacy
     * convention, where mysqli returns false instead of throwing.
     */
    public function testPingReportsFalseWhenTheProbeQueryFails()
    {
        $this->db->fake->queryResult = false;

        $this->assertFalse($this->db->ping());
    }

    /**
     * ... and the PHP 8.1+ default convention, where mysqli throws instead.
     */
    public function testPingReportsFalseWhenTheProbeQueryThrows()
    {
        $this->db->fake->queryThrows = 'MySQL server has gone away';

        $this->assertFalse($this->db->ping());
    }

    /**
     * A NULL in the mapped column used to be handed straight to an array offset, which
     * PHP 8.5 deprecated. PHP folds a null key to '', so that is the key to expect.
     */
    public function testMapWithANullKeyColumnDoesNotUseNullAsAnArrayOffset()
    {
        // Three columns, so map() keeps the whole row instead of collapsing it to the
        // last value the way it does for a two-column select.
        $this->db->nextResultRows = array(
            array('id' => null, 'login' => 'anonymous', 'age' => '30'),
        );

        $rows = $this->db->map('id')->get('users');

        $this->assertSame(array(''), array_keys($rows));
        $this->assertSame(array('id' => null, 'login' => 'anonymous', 'age' => '30'), $rows['']);
    }

    /**
     * Non-null keys must keep working exactly as before.
     */
    public function testMapStillKeysRowsByTheMappedColumn()
    {
        $this->db->nextResultRows = array(
            array('id' => '7', 'login' => 'demo', 'age' => '30'),
            array('id' => '9', 'login' => 'other', 'age' => '41'),
        );

        $rows = $this->db->map('id')->get('users');

        // PHP normalises integer-like string keys to int, as it always has here.
        $this->assertSame(array(7, 9), array_keys($rows));
        $this->assertSame('demo', $rows[7]['login']);
        $this->assertSame('other', $rows[9]['login']);
    }

    /**
     * Passing null to trim() has been deprecated since PHP 8.1. join()'s third argument
     * is optional, so a caller forwarding a nullable config value lands right on it.
     */
    public function testJoinAcceptsANullJoinType()
    {
        $built = $this->db
            ->join('posts', 'posts.user_id = users.id', null)
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        $this->assertStringContainsString('JOIN posts', $built['sql']);
    }

    /**
     * Same for orderBy()'s direction, which defaults to a string.
     */
    public function testOrderByRejectsANullDirectionWithoutADeprecation()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Wrong order direction');

        $this->db->orderBy('id', null);
    }
}
