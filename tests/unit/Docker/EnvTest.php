<?php

namespace Tests\Unit\Docker;

use Appwrite\Docker\Env;
use Exception;
use PHPUnit\Framework\TestCase;

class EnvTest extends TestCase
{
    protected ?Env $object = null;

    public function setUp(): void
    {
        $data = @file_get_contents(__DIR__ . '/../../resources/docker/.env');

        if ($data === false) {
            throw new Exception('Failed to read compose file');
        }

        $this->object = new Env($data);
    }

    public function testVars(): void
    {
        $this->object->setVar('_APP_TEST', 'value4');

        $this->assertEquals('value1', $this->object->getVar('_APP_X'));
        $this->assertEquals('value2', $this->object->getVar('_APP_Y'));
        $this->assertEquals('value3', $this->object->getVar('_APP_Z'));
        $this->assertEquals('value5=', $this->object->getVar('_APP_W'));
        $this->assertEquals('value4', $this->object->getVar('_APP_TEST'));
    }

    public function testExport(): void
    {
        $this->assertEquals("_APP_X=value1
_APP_Y=value2
_APP_Z=value3
", $this->object->export());
    }
}
