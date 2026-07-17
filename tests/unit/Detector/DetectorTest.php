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
        $this->assertSame([
            'osCode' => 'WIN',
            'osName' => 'Windows',
            'osVersion' => '7',
        ], $this->object->getOS());
    }

    public function testGetClient(): void
    {
        $this->assertSame([
            'clientType' => 'browser',
            'clientCode' => 'FF',
            'clientName' => 'Firefox',
            'clientVersion' => '47.0',
            'clientEngine' => 'Gecko',
            'clientEngineVersion' => '47.0',
        ], $this->object->getClient());
    }

    public function testGetDevice(): void
    {
        $this->assertSame([
            'deviceName' => 'desktop',
            'deviceBrand' => null,
            'deviceModel' => null,
        ], $this->object->getDevice());
    }

    public function testAppwriteCLI(): void
    {
        $detector = new Detector('AppwriteCLI/8.2.0 Darwin/24.5.0 arm64');

        $this->assertSame([
            'clientType' => 'desktop',
            'clientCode' => 'cli',
            'clientName' => 'Appwrite CLI',
            'clientVersion' => '8.2.0',
            'clientEngine' => '',
            'clientEngineVersion' => '',
        ], $detector->getClient());
    }

    public function testBotDetectionDoesNotSuppressClientOrDevice(): void
    {
        $detector = new Detector(
            'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 '
            . 'Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        );

        $this->assertSame('Chrome Mobile', $detector->getClient()['clientName']);
        $this->assertSame('smartphone', $detector->getDevice()['deviceName']);
    }
}
