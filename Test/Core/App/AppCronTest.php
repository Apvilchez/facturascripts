<?php

namespace FacturaScripts\Core\App;

/**
 * Generated by PHPUnit_SkeletonGenerator on 2017-07-19 at 11:58:07.
 */
class AppCronTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var AppCron
     */
    protected $object;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {
        $this->object = new AppCron(PHPUNIT_PATH);
    }

    /**
     * Tears down the fixture, for example, closes a network connection.
     * This method is called after a test is executed.
     */
    protected function tearDown()
    {
    }

    public function testConnect()
    {
        $this->assertTrue($this->object->connect());
    }

    /**
     * @covers \FacturaScripts\Core\App\AppCron::run
     */
    public function testRun()
    {
        $this->assertTrue($this->object->run());
    }
}
