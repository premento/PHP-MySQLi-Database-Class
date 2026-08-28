<?php

/**
 * Error reporting, state resetting and the helper methods around them.
 */
class ErrorHandlingTest extends BuilderTestCase
{
    /**
     * Regression: reset() never cleared the recorded statement error, so getLastError()
     * kept reporting a previous failure after a later query succeeded.
     */
    public function testErrorDoesNotSurviveIntoTheNextQuery()
    {
        $this->db->nextExecuteThrows = 'first failure';

        try {
            $this->db->where('id', 1)->delete('users');
        } catch (Exception $e) {
            // expected
        }

        $this->assertSame('first failure', $this->db->getLastError());

        $this->db->nextExecuteThrows = null;
        $this->db->insert('users', array('login' => 'demo'));

        $this->assertSame('', $this->db->getLastError());
        $this->assertSame(0, $this->db->getLastErrno());
    }

    public function testGetLastQueryInterpolatesBoundValues()
    {
        $this->db->where('id', 7)->where('login', 'demo')->update('users', array('age' => 30));

        $this->assertSqlEquals(
            "UPDATE users SET `age` = '30' WHERE id = '7' AND login = 'demo'",
            $this->db->getLastQuery()
        );
    }

    /**
     * Regression: the placeholder loop was `while ($pos = strpos($str, '?'))`, which
     * stopped immediately when the first placeholder sat at offset 0.
     */
    public function testPlaceholderAtOffsetZeroIsReplaced()
    {
        $method = new ReflectionMethod(MysqliDb::class, 'replacePlaceHolders');
        $method->setAccessible(true);

        $this->assertSame("'a' = x", $method->invoke($this->db, '? = x', array('', 'a')));
    }

    /**
     * Regression: more placeholders than bound values read past the end of the array.
     */
    public function testMorePlaceholdersThanValuesDoesNotOverrun()
    {
        $method = new ReflectionMethod(MysqliDb::class, 'replacePlaceHolders');
        $method->setAccessible(true);

        $this->assertSame("x = 'a' AND y = ?", $method->invoke($this->db, 'x = ? AND y = ?', array('', 'a')));
    }

    public function testNullValuesAreRenderedAsNullInTheDebugQuery()
    {
        $method = new ReflectionMethod(MysqliDb::class, 'replacePlaceHolders');
        $method->setAccessible(true);

        $this->assertSame("x = 'NULL'", $method->invoke($this->db, 'x = ?', array('', null)));
    }

    /**
     * Regression: `case "READ" || "WRITE":` evaluates to `case true:`, so every
     * non-empty string was accepted as a lock method.
     */
    public function testSetLockMethodRejectsAnythingOtherThanReadOrWrite()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Bad lock type');

        $this->db->setLockMethod('DROP');
    }

    public function testSetLockMethodAcceptsReadAndWriteCaseInsensitively()
    {
        $this->assertSame($this->db, $this->db->setLockMethod('read'));
        $this->assertSame($this->db, $this->db->setLockMethod('WRITE'));
    }

    public function testLockBuildsOneStatementForMultipleTables()
    {
        $this->db->setLockMethod('READ');
        $this->db->lock(array('users', 'products'));

        $this->assertSame(
            array('LOCK TABLES users READ, products READ'),
            $this->db->fake->unpreparedQueries
        );
    }

    /**
     * Regression: comma placement was driven by the array key, so a non-sequential
     * array produced "LOCK TABLES a READ b READ" with no separator.
     */
    public function testLockHandlesNonSequentialArrayKeys()
    {
        $this->db->setLockMethod('READ');
        $this->db->lock(array(5 => 'users', 9 => 'products'));

        $this->assertSame(
            array('LOCK TABLES users READ, products READ'),
            $this->db->fake->unpreparedQueries
        );
    }

    public function testLockAppliesPrefix()
    {
        MysqliDb::$prefix = 't_';
        $this->db->setLockMethod('WRITE');
        $this->db->lock('users');

        $this->assertSame(array('LOCK TABLES t_users WRITE'), $this->db->fake->unpreparedQueries);
    }

    public function testUnlock()
    {
        $this->db->unlock();

        $this->assertSame(array('UNLOCK TABLES'), $this->db->fake->unpreparedQueries);
    }

    /**
     * Regression: _determineType() returned '' for unsupported types, desynchronising the
     * mysqli type string from the number of bound values.
     */
    public function testUnsupportedBindTypeIsRejectedEarly()
    {
        $method = new ReflectionMethod(MysqliDb::class, '_determineType');
        $method->setAccessible(true);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported bind parameter type: array');

        $method->invoke($this->db, array('nested'));
    }

    /**
     * Any object other than a subquery used to fall into the subquery path and die with
     * "Call to undefined method ...::getSubQuery()".
     */
    public function testNonSubQueryObjectIsRejectedWithAClearMessage()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Only MysqliDb subquery objects can be used as a value');

        $this->db->where('createdAt', new DateTime())->startQuery('SELECT * FROM users')->buildOnly();
    }

    public function testDetermineTypeMapping()
    {
        $method = new ReflectionMethod(MysqliDb::class, '_determineType');
        $method->setAccessible(true);

        $this->assertSame('s', $method->invoke($this->db, 'text'));
        $this->assertSame('s', $method->invoke($this->db, null));
        $this->assertSame('i', $method->invoke($this->db, 5));
        $this->assertSame('i', $method->invoke($this->db, true));
        $this->assertSame('d', $method->invoke($this->db, 1.5));
    }

    /**
     * Regression: escape() used a truthiness check, so "0" was returned unescaped and
     * non-strings triggered a PHP 8.1 deprecation inside real_escape_string().
     */
    public function testEscapeHandlesFalsyAndNonStringValues()
    {
        $this->assertNull($this->db->escape(null));
        $this->assertSame('0', $this->db->escape(0));
        $this->assertSame('', $this->db->escape(''));
        $this->assertSame('5', $this->db->escape(5));
        $this->assertSame("a\\'b", $this->db->escape("a'b"));
    }

    /**
     * Regression: paginate() divided by pageLimit without checking it.
     */
    public function testPaginateRejectsAZeroPageLimit()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('pageLimit must be a positive integer');

        $this->db->pageLimit = 0;
        $this->db->paginate('users', 1);
    }

    public function testPaginateBuildsOffsetFromPageNumber()
    {
        $this->db->pageLimit = 15;
        $this->db->paginate('users', 3);

        $this->assertSqlContains('LIMIT 30, 15', $this->db->lastPrepared());
    }

    public function testPaginateClampsPageNumbersBelowOne()
    {
        $this->db->pageLimit = 10;
        $this->db->paginate('users', 0);

        $this->assertSqlContains('LIMIT 0, 10', $this->db->lastPrepared());
    }

    /**
     * Regression: copy() serialised the object while it still held mysqli handles,
     * which throws "Serialization of 'mysqli' is not allowed".
     */
    public function testCopyWorksOnAConnectedInstance()
    {
        $db = new MysqliDb('localhost', 'user', 'pass', 'testdb');

        // Simulate a live connection without needing a server.
        $property = new ReflectionProperty(MysqliDb::class, '_mysqli');
        $property->setAccessible(true);
        $property->setValue($db, array('default' => new FakeMysqli()));

        $copy = $db->copy();

        $this->assertInstanceOf(MysqliDb::class, $copy);
        $this->assertNotSame($db, $copy);
        $this->assertSame(array(), $property->getValue($copy), 'the copy must not share connections');
        $this->assertNotSame(array(), $property->getValue($db), 'the original keeps its connection');
    }

    public function testIntervalBuildsDateArithmetic()
    {
        $this->assertSame('NOW() + interval 1 day ', $this->db->interval('1d'));
        $this->assertSame('NOW() - interval 1 year ', $this->db->interval('-1Y'));
        $this->assertSame('NOW() + interval 10 minute ', $this->db->interval('10m'));
    }

    public function testIntervalRejectsAnUnknownUnit()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('invalid interval type');

        $this->db->interval('1x');
    }

    public function testIncAndDecRejectNonNumericArguments()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('must be a number');

        $this->db->inc('abc');
    }

    public function testSubQueryProducesAQueryAndParameters()
    {
        $sub = MysqliDb::subQuery('sq');
        $sub->where('qty', 2, '>');
        $sub->get('products', null, 'userId');

        $parts = $sub->getSubQuery();

        $this->assertSqlEquals('SELECT userId FROM products WHERE qty > ?', $parts['query']);
        $this->assertSame(array(2), $parts['params']);
        $this->assertSame('sq', $parts['alias']);
    }

    public function testWhereWithSubQueryBindsTheInnerParameters()
    {
        $sub = MysqliDb::subQuery();
        $sub->where('qty', 2, '>');
        $sub->get('products', null, 'userId');

        $built = $this->db->where('id', $sub, 'in')
            ->startQuery('SELECT * FROM users')
            ->buildOnly();

        // Documented output is a plain IN (SELECT ...). Wrapping the subquery in a second
        // pair of parentheses makes MySQL evaluate it as a scalar subquery and fail with
        // "Subquery returns more than 1 row".
        $this->assertSqlContains('WHERE id in (SELECT userId FROM products WHERE qty > ?)', $built['sql']);
        $this->assertStringNotContainsString('( (SELECT', $built['sql']);
        $this->assertSame(array(2), $built['values']);
    }

    public function testExistsSubQueryHasNoTrailingAlias()
    {
        $sub = MysqliDb::subQuery('sq');
        $sub->where('company', 'testCompany');
        $sub->get('users', null, 'userId');

        $built = $this->db->where(null, $sub, 'exists')
            ->startQuery('SELECT * FROM products')
            ->buildOnly();

        $this->assertSqlContains('exists (SELECT userId FROM users WHERE company = ?)', $built['sql']);
        $this->assertStringNotContainsString('sq', $built['sql']);
    }

    /**
     * The constructor accepts an array of settings. It used to expand it with variable
     * variables, which let an arbitrary key overwrite unrelated local state.
     */
    public function testArrayConstructorOnlyReadsKnownKeys()
    {
        MysqliDb::$prefix = '';

        $db = new TestableMysqliDb(array(
            'host' => 'localhost',
            'username' => 'user',
            'password' => 'pass',
            'db' => 'testdb',
            'prefix' => 'p_',
            'isSubQuery' => false,
            'somethingElse' => 'ignored',
        ));

        $this->assertSame('p_', MysqliDb::$prefix);
        $db->insert('users', array('login' => 'demo'));
        $this->assertSqlContains('INTO p_users', $db->lastPrepared());
    }

    public function testSubQueryFlagViaArrayConstructor()
    {
        $db = new MysqliDb(array('host' => 'alias', 'isSubQuery' => true));

        $this->assertNull($db->update('users', array('login' => 'x')));
    }

    public function testDeleteInsideASubQueryIsRejected()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('cannot be used within a subquery');

        $sub = MysqliDb::subQuery('sq');
        $sub->delete('users');
    }

    public function testConnectionProfileMustExist()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('was not added');

        $this->db->connection('nope');
    }
}
