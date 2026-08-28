<?php

/**
 * dbObject model behaviour.
 */
class DbObjectTest extends ModelTestCase
{
    public function testInsertBuildsAStatementFromTheObjectProperties()
    {
        $user = new TestUserModel();
        $user->login = 'demo';
        $user->customerId = 10;

        $this->db->nextInsertId = 5;
        $id = $user->insert();

        $this->assertSame(5, $id);
        $this->assertSqlContains('INSERT  INTO users (`login`, `customerId`)', $this->db->lastPrepared());
        $this->assertSame(5, $user->id, 'the primary key is written back onto the object');
        $this->assertFalse($user->isNew);
    }

    /**
     * Regression: prepareData() called count() on a null $data, which is a TypeError in
     * PHP 8, so inserting an object with nothing set was a fatal error.
     */
    public function testInsertingAnObjectWithNoDataDoesNotFatal()
    {
        $user = new TestLooseModel();

        // No properties set at all. This line used to be a fatal error:
        // "count(): Argument #1 ($value) must be of type Countable|array, null given".
        $result = $user->insert();

        $this->assertTrue($result);
    }

    public function testValidationRejectsAMissingRequiredField()
    {
        $user = new TestUserModel();
        $user->customerId = 10;

        $this->assertFalse($user->insert());
        $this->assertSame(array(array('users.login' => 'is required')), $user->errors);
    }

    public function testValidationRejectsAValueThatFailsItsPattern()
    {
        $user = new TestUserModel();
        $user->login = 'demo';
        $user->firstName = 'not-valid!';

        $this->assertFalse($user->insert());
        $this->assertSame(
            array(array('users.firstName' => '/^[a-zA-Z0-9 ]+$/ validation failed')),
            $user->errors
        );
    }

    public function testValidationPassesForAWellFormedObject()
    {
        $user = new TestUserModel();
        $user->login = 'demo';
        $user->firstName = 'John Doe';
        $user->customerId = 10;
        $user->active = 1;

        $this->db->nextInsertId = 1;

        $this->assertSame(1, $user->insert());
        $this->assertSame(array(), $user->errors);
    }

    /**
     * Falsy values must not trip the pattern checks; that was the behaviour before the
     * PHP 8.1 strlen(null) deprecation was addressed and it has to stay that way.
     */
    public function testFalsyValuesSkipPatternValidation()
    {
        $user = new TestUserModel();
        $user->login = 'demo';
        $user->active = 0;
        $user->customerId = 0;
        $user->firstName = '';

        $this->db->nextInsertId = 1;

        $this->assertSame(1, $user->insert());
        $this->assertSame(array(), $user->errors);
    }

    public function testUpdateBuildsAWhereOnThePrimaryKey()
    {
        $user = new TestUserModel(array('id' => 3, 'login' => 'demo'));
        $user->isNew = false;
        $user->login = 'changed';

        $user->update();

        $this->assertSqlContains('UPDATE users SET', $this->db->lastPrepared());
        $this->assertSqlContains('WHERE id = ?', $this->db->lastPrepared());
    }

    public function testUpdateReturnsFalseWithoutAPrimaryKeyValue()
    {
        $user = new TestUserModel(array('login' => 'demo'));
        $user->isNew = false;

        $this->assertFalse($user->update());
    }

    public function testSaveDispatchesToInsertThenUpdate()
    {
        $user = new TestUserModel();
        $user->login = 'demo';

        $this->db->nextInsertId = 9;
        $user->save();
        $this->assertStringStartsWith('INSERT', $this->db->lastPrepared());

        $user->login = 'changed';
        $user->save();
        $this->assertStringStartsWith('UPDATE', $this->db->lastPrepared());
    }

    public function testDeleteUsesThePrimaryKey()
    {
        $user = new TestUserModel(array('id' => 4));
        $user->isNew = false;

        $this->assertTrue($user->delete());
        $this->assertSqlEqualsIgnoringSpace('DELETE FROM users WHERE id = ?', $this->db->lastPrepared());
    }

    public function testDeleteReturnsFalseWithoutAPrimaryKeyValue()
    {
        $user = new TestUserModel();

        $this->assertFalse($user->delete());
    }

    public function testHiddenFieldsAreNotReadableOrWritable()
    {
        $profile = new TestProfileModel(array('login' => 'demo', 'secret' => 'shh'));

        $this->assertNull($profile->secret);

        $profile->secret = 'new value';
        $this->assertSame('shh', $profile->data['secret'], 'writes to a hidden field are ignored');
    }

    public function testTimestampsArePopulated()
    {
        $profile = new TestProfileModel();
        $profile->login = 'demo';

        $this->db->nextInsertId = 1;
        $profile->insert();

        $this->assertArrayHasKey('createdAt', $profile->data);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $profile->data['createdAt']);
    }

    public function testJsonAndArrayFieldsAreSerialisedOnSave()
    {
        $profile = new TestProfileModel();
        $profile->login = 'demo';
        $profile->options = array('a' => 1);
        $profile->sections = array('one', 'two');

        $this->db->nextInsertId = 1;
        $profile->insert();

        $values = $this->db->lastBoundValues();

        $this->assertContains('{"a":1}', $values);
        $this->assertContains('one|two', $values);
    }

    public function testSkipExcludesFieldsFromTheStatement()
    {
        $user = new TestUserModel();
        $user->login = 'demo';
        $user->customerId = 10;

        $this->db->nextInsertId = 1;
        $user->skip('customerId')->insert();

        $this->assertSqlContains('(`login`)', $this->db->lastPrepared());
        $this->assertStringNotContainsString('customerId', $this->db->lastPrepared());
    }

    public function testSkipFalseClearsTheSkipList()
    {
        $user = new TestUserModel();
        $user->login = 'demo';
        $user->customerId = 10;

        $this->db->nextInsertId = 1;
        $user->skip('customerId')->skip(false)->insert();

        $this->assertSqlContains('customerId', $this->db->lastPrepared());
    }

    /**
     * Regression: __call() discarded whatever the MysqliDb method returned and always
     * answered with the dbObject, so value-returning methods were unusable.
     */
    public function testCallPassesThroughRealReturnValues()
    {
        $user = new TestUserModel();

        $this->db->nextExecuteThrows = 'some failure';
        try {
            $this->db->where('id', 1)->delete('users');
        } catch (Exception $e) {
            // expected
        }

        $this->assertSame('some failure', $user->getLastError());
    }

    public function testCallStaysFluentForBuilderMethods()
    {
        $user = new TestUserModel();

        $this->assertInstanceOf(TestUserModel::class, $user->where('login', 'demo'));
        $this->assertInstanceOf(TestUserModel::class, $user->orderBy('id', 'ASC'));
    }

    /**
     * Regression: with() used die() for an unknown relation, killing the process instead
     * of letting the caller handle it.
     */
    public function testUnknownRelationThrowsInsteadOfKillingTheProcess()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No relation with name nope found');

        $user = new TestUserModel();
        $user->with('nope');
    }

    /**
     * Regression: table() ran its argument through eval() after a filter that still
     * allowed a leading digit, producing a parse error.
     */
    public function testVirtualTableRejectsANameThatIsNotAValidIdentifier()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid table name');

        dbObject::table('9invalid');
    }

    public function testVirtualTableCreatesAUsableModel()
    {
        $model = dbObject::table('virtual_things');

        $this->assertInstanceOf(dbObject::class, $model);
        $this->assertSame('virtual_things', get_class($model));

        // A virtual model has no $dbFields, so every property is persisted and the
        // table name is taken from the class name.
        $model->name = 'x';
        $this->db->nextInsertId = 1;
        $model->insert();

        $this->assertSqlContains('INTO virtual_things', $this->db->lastPrepared());
    }

    public function testToArrayReturnsThePlainData()
    {
        $user = new TestUserModel(array('id' => 1, 'login' => 'demo'));

        $this->assertSame(array('id' => 1, 'login' => 'demo'), $user->toArray());
    }

    public function testToJson()
    {
        $user = new TestUserModel(array('id' => 1, 'login' => 'demo'));

        $this->assertSame('{"id":1,"login":"demo"}', $user->toJson());
        $this->assertSame('{"id":1,"login":"demo"}', (string) $user);
    }

    public function testConstructorFailsClearlyWithoutAMysqliDbInstance()
    {
        $property = new ReflectionProperty(MysqliDb::class, '_instance');
        $property->setAccessible(true);
        $previous = $property->getValue();
        $property->setValue(null, null);

        try {
            $this->expectException(Exception::class);
            $this->expectExceptionMessage('dbObject requires a MysqliDb instance');
            new TestUserModel();
        } finally {
            $property->setValue(null, $previous);
        }
    }

    private function assertSqlEqualsIgnoringSpace($expected, $actual)
    {
        $this->assertSame(
            TestableMysqliDb::normalize($expected),
            TestableMysqliDb::normalize($actual)
        );
    }

    private function assertSqlContains($needle, $haystack)
    {
        $this->assertStringContainsString(
            TestableMysqliDb::normalize($needle),
            TestableMysqliDb::normalize($haystack)
        );
    }
}
