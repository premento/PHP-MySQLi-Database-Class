<?php

/**
 * rawAddPrefix() rewrites table names in raw queries. It is applied to every
 * rawQuery(), so getting it wrong corrupts arbitrary user SQL.
 */
class RawAddPrefixTest extends BuilderTestCase
{
    public function testIsANoOpWhenNoPrefixIsConfigured()
    {
        MysqliDb::$prefix = '';

        $query = "SELECT * FROM users WHERE note = 'update me'";

        $this->assertSame($query, $this->db->rawAddPrefix($query));
    }

    public function testPrefixesASimpleFromClause()
    {
        MysqliDb::$prefix = 't_';

        $this->assertSame(
            'SELECT * FROM `t_users`',
            $this->db->rawAddPrefix('SELECT * FROM users')
        );
    }

    public function testPrefixesEveryTableInTheQuery()
    {
        MysqliDb::$prefix = 't_';

        $this->assertSame(
            'SELECT * FROM `t_users` JOIN `t_products` ON products.userId = users.id',
            $this->db->rawAddPrefix('SELECT * FROM users JOIN products ON products.userId = users.id')
        );
    }

    public function testPrefixesInsertIntoAndUpdate()
    {
        MysqliDb::$prefix = 't_';

        $this->assertSame(
            'INSERT INTO `t_users` (login) VALUES (?)',
            $this->db->rawAddPrefix('INSERT INTO users (login) VALUES (?)')
        );

        $this->assertSame(
            'UPDATE `t_users` SET login = ?',
            $this->db->rawAddPrefix('UPDATE users SET login = ?')
        );
    }

    /**
     * Regression: the keyword scan used to fire inside string literals, so a query
     * carrying the word "update" in its data was silently rewritten.
     */
    public function testDoesNotRewriteInsideSingleQuotedStrings()
    {
        MysqliDb::$prefix = 't_';

        $query = "SELECT * FROM users WHERE note = 'update products please'";

        $this->assertSame(
            "SELECT * FROM `t_users` WHERE note = 'update products please'",
            $this->db->rawAddPrefix($query)
        );
    }

    public function testDoesNotRewriteInsideDoubleQuotedStrings()
    {
        MysqliDb::$prefix = 't_';

        $query = 'SELECT * FROM users WHERE note = "join orders"';

        $this->assertSame(
            'SELECT * FROM `t_users` WHERE note = "join orders"',
            $this->db->rawAddPrefix($query)
        );
    }

    /**
     * Regression: "ON DUPLICATE KEY UPDATE views" became "UPDATE `prefix_views`",
     * breaking every upsert written as a raw query.
     */
    public function testDoesNotTreatOnDuplicateKeyUpdateAsAnUpdateStatement()
    {
        MysqliDb::$prefix = 't_';

        $query = 'INSERT INTO stats (id, views) VALUES (?, ?) ON DUPLICATE KEY UPDATE views = views + 1';

        $this->assertSame(
            'INSERT INTO `t_stats` (id, views) VALUES (?, ?) ON DUPLICATE KEY UPDATE views = views + 1',
            $this->db->rawAddPrefix($query)
        );
    }

    /**
     * Regression: newlines were stripped before the scan, which merged a "--" comment
     * into the following line and commented out the rest of the statement.
     */
    public function testPreservesNewlinesSoLineCommentsStayScopedToTheirLine()
    {
        MysqliDb::$prefix = 't_';

        $query = "SELECT id -- pick the id\nFROM users";
        $result = $this->db->rawAddPrefix($query);

        $this->assertStringContainsString("\n", $result);
        $this->assertSame("SELECT id -- pick the id\nFROM `t_users`", $result);
    }

    public function testDoesNotRewriteInsideLineComments()
    {
        MysqliDb::$prefix = 't_';

        $query = "-- from products\nSELECT * FROM users";

        $this->assertSame("-- from products\nSELECT * FROM `t_users`", $this->db->rawAddPrefix($query));
    }

    public function testDoesNotRewriteInsideBlockComments()
    {
        MysqliDb::$prefix = 't_';

        $query = 'SELECT * /* from products */ FROM users';

        $this->assertSame('SELECT * /* from products */ FROM `t_users`', $this->db->rawAddPrefix($query));
    }

    /**
     * Regression: a query already written with the prefix came back double prefixed.
     */
    public function testDoesNotPrefixATableThatIsAlreadyPrefixed()
    {
        MysqliDb::$prefix = 't_';

        $this->assertSame(
            'SELECT * FROM t_users',
            $this->db->rawAddPrefix('SELECT * FROM t_users')
        );
    }

    /**
     * Regression: "FROM otherdb.users" used to become "FROM `prefix_otherdb`.users",
     * prefixing the schema name instead of the table.
     */
    public function testLeavesSchemaQualifiedNamesAlone()
    {
        MysqliDb::$prefix = 't_';

        $this->assertSame(
            'SELECT * FROM otherdb.users',
            $this->db->rawAddPrefix('SELECT * FROM otherdb.users')
        );
    }

    public function testDoesNotPrefixKeywordsThatFollowFromOrInto()
    {
        MysqliDb::$prefix = 't_';

        $this->assertSame('SELECT NOW() FROM DUAL', $this->db->rawAddPrefix('SELECT NOW() FROM DUAL'));
        $this->assertSame(
            "SELECT * INTO OUTFILE '/tmp/x' FROM `t_users`",
            $this->db->rawAddPrefix("SELECT * INTO OUTFILE '/tmp/x' FROM users")
        );
    }

    public function testHandlesBackquotedTableNames()
    {
        MysqliDb::$prefix = 't_';

        $this->assertSame(
            'SELECT * FROM `t_users`',
            $this->db->rawAddPrefix('SELECT * FROM `users`')
        );
    }

    /**
     * The opening parenthesis of a derived table must not be mistaken for a table name,
     * while the real table inside the subquery still gets prefixed.
     */
    public function testPrefixesInsideDerivedTablesWithoutTouchingTheParenthesis()
    {
        MysqliDb::$prefix = 't_';

        $this->assertSame(
            'SELECT * FROM (SELECT id FROM `t_users`) x',
            $this->db->rawAddPrefix('SELECT * FROM (SELECT id FROM users) x')
        );
    }
}
