<?php

use PHPUnit\Framework\TestCase;

/**
 * Base class for dbObject tests.
 *
 * dbObject reaches for MysqliDb::getInstance(), so a stub instance is registered as the
 * singleton before each test.
 */
abstract class ModelTestCase extends TestCase
{
    /** @var TestableMysqliDb */
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        MysqliDb::$prefix = '';
        // The constructor registers the new object as MysqliDb::$_instance, which is what
        // dbObject::__construct() picks up.
        $this->db = new TestableMysqliDb('localhost', 'user', 'pass', 'testdb');
    }

    protected function tearDown(): void
    {
        MysqliDb::$prefix = '';
        parent::tearDown();
    }
}

/**
 * Minimal model used across the dbObject tests.
 */
class TestUserModel extends dbObject
{
    protected $dbTable = 'users';
    protected $primaryKey = 'id';
    protected $dbFields = array(
        'login' => array('text', 'required'),
        'active' => array('bool'),
        'customerId' => array('int'),
        'firstName' => array('/^[a-zA-Z0-9 ]+$/'),
    );
}

/**
 * Model exercising timestamps, hidden columns and the json/array field conversions.
 */
class TestProfileModel extends dbObject
{
    protected $dbTable = 'profiles';
    protected $primaryKey = 'id';
    protected $dbFields = array(
        'login' => array('text'),
        'secret' => array('text'),
        'options' => array('text'),
        'sections' => array('text'),
        'createdAt' => array('datetime'),
        'updatedAt' => array('datetime'),
    );
    protected $timestamps = array('createdAt', 'updatedAt');
    protected $hidden = array('secret');
    protected $jsonFields = array('options');
    protected $arrayFields = array('sections');
}

/**
 * Model with no $dbFields at all, to check the no-validation path.
 */
class TestLooseModel extends dbObject
{
    protected $dbTable = 'loose';
}
