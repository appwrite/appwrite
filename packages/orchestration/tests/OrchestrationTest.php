<?php

namespace Utopia\Orchestration\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Orchestration\Adapter\DockerCLI;
use Utopia\Orchestration\Orchestration;

class OrchestrationTest extends TestCase
{
    public function testParseCLICommand(): void
    {
        $orchestration = new Orchestration(new DockerCLI());

        /**
         * Test for success
         */
        $test = $orchestration->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'");

        $this->assertSame([
            'sh',
            '-c',
            "'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null'",
        ], $test);

        $test = $orchestration->parseCommandString('sudo apt-get update');

        $this->assertSame([
            'sudo',
            'apt-get',
            'update',
        ], $test);

        $test = $orchestration->parseCommandString('test');

        $this->assertSame([
            'test',
        ], $test);

        /**
         * Test for failure
         */
        $this->expectException(\Exception::class);

        $orchestration->parseCommandString("sh -c 'mv /tmp/code.tar.gz /usr/local/src/code.tar.gz && tar -zxf /usr/local/src/code.tar.gz --strip 1 && rm /usr/local/src/code.tar.gz && tail -f /dev/null");
    }
}
