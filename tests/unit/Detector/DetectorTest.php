<?php

declare(strict_types=1);

namespace Tests\Unit\Detector;

use Appwrite\Detector\Detector;
use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase
{
    protected ?Detector $object = null;

    public function setUp(): void
    {
        $this->object = new Detector('Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0');
    }

    public function tearDown(): void
    {
    }

    public function testGetOS(): void
    {
        $this->assertEquals($this->object->getOS(), [
            'osCode' => 'WIN',
            'osName' => 'Windows',
            'osVersion' => '7',
        ]);
    }

    public function testGetClient(): void
    {
        $this->assertEquals($this->object->getClient(), [
            'clientType' => 'browser',
            'clientCode' => 'FF',
            'clientName' => 'Firefox',
            'clientVersion' => '47.0',
            'clientEngine' => 'Gecko',
            'clientEngineVersion' => '47.0',
        ]);
    }

    public function testGetDevice(): void
    {
        $this->assertEquals($this->object->getDevice(), [
            'deviceName' => 'desktop',
            'deviceBrand' => '',
            'deviceModel' => '',
        ]);
    }

    public function testMobileClient(): void
    {
        $detector = new Detector('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1');

        $this->assertEquals(['osCode' => 'IOS', 'osName' => 'iOS', 'osVersion' => '17.0'], $detector->getOS());
        $this->assertEquals('MF', $detector->getClient()['clientCode']);
        $this->assertEquals([
            'deviceName' => 'smartphone',
            'deviceBrand' => 'Apple',
            'deviceModel' => 'iPhone',
        ], $detector->getDevice());
    }

    public function testCliClient(): void
    {
        $detector = new Detector('AppwriteCLI/2.0.0 (linux; x64) node/18.0.0');

        $this->assertEquals([
            'clientType' => 'desktop',
            'clientCode' => 'cli',
            'clientName' => 'Appwrite CLI',
            'clientVersion' => '2.0.0',
            'clientEngine' => '',
            'clientEngineVersion' => '',
        ], $detector->getClient());
    }

    public function testUnknownUserAgent(): void
    {
        $detector = new Detector('');

        // OS and client fields default to an empty string, device fields to null.
        $this->assertEquals(['osCode' => '', 'osName' => '', 'osVersion' => ''], $detector->getOS());
        $this->assertEquals('', $detector->getClient()['clientType']);
        $this->assertEquals([
            'deviceName' => null,
            'deviceBrand' => null,
            'deviceModel' => null,
        ], $detector->getDevice());
    }
}
