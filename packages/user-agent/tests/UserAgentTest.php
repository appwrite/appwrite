<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\UserAgent\UserAgent;

final class UserAgentTest extends TestCase
{
    public function testFirefoxOnWindowsMatchesReferenceContract(): void
    {
        $agent = UserAgent::parse(
            'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0',
        );

        $this->assertSame([
            'code' => 'WIN',
            'name' => 'Windows',
            'version' => '7',
        ], $agent->operatingSystem()->toArray());
        $this->assertSame([
            'type' => 'browser',
            'code' => 'FF',
            'name' => 'Firefox',
            'version' => '47.0',
            'engine' => 'Gecko',
            'engineVersion' => '47.0',
        ], $agent->client()->toArray());
        $this->assertSame([
            'type' => 'desktop',
            'brand' => null,
            'model' => null,
        ], $agent->device()->toArray());
        $this->assertFalse($agent->isBot());
    }

    public function testIphoneSafariMatchesActivityDimensions(): void
    {
        $agent = UserAgent::parse(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) '
            . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 '
            . 'Mobile/15E148 Safari/604.1',
        );

        $this->assertSame('IOS', $agent->operatingSystem()->code);
        $this->assertSame('iOS', $agent->operatingSystem()->name);
        $this->assertSame('17.4', $agent->operatingSystem()->version);
        $this->assertSame('MF', $agent->client()->code);
        $this->assertSame('Mobile Safari', $agent->client()->name);
        $this->assertSame('WebKit', $agent->client()->engine);
        $this->assertSame('smartphone', $agent->device()->type);
        $this->assertSame('Apple', $agent->device()->brand);
        $this->assertSame('iPhone', $agent->device()->model);
    }

    public function testAndroidChromeDetectsModelAndBrand(): void
    {
        $agent = UserAgent::parse(
            'Mozilla/5.0 (Linux; Android 13; Pixel 7 Pro Build/TQ3A.230805.001) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        );

        $this->assertSame('AND', $agent->operatingSystem()->code);
        $this->assertSame('13', $agent->operatingSystem()->version);
        $this->assertSame('CM', $agent->client()->code);
        $this->assertSame('Chrome Mobile', $agent->client()->name);
        $this->assertSame('smartphone', $agent->device()->type);
        $this->assertSame('Google', $agent->device()->brand);
        $this->assertSame('Pixel 7 Pro', $agent->device()->model);
    }

    public function testTabletDoesNotRequireMobileToken(): void
    {
        $agent = UserAgent::parse(
            'Mozilla/5.0 (Linux; Android 12; SM-T970 Build/SP1A.210812.016) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36',
        );

        $this->assertSame('tablet', $agent->device()->type);
        $this->assertSame('Samsung', $agent->device()->brand);
        $this->assertSame('SM-T970', $agent->device()->model);
    }

    public function testTabletModelOverridesMobileToken(): void
    {
        $agent = UserAgent::parse(
            'Mozilla/5.0 (Linux; Android 14; SM-X910) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        );

        $this->assertSame('tablet', $agent->device()->type);
        $this->assertSame('Samsung', $agent->device()->brand);
        $this->assertSame('SM-X910', $agent->device()->model);
    }

    public function testBotDetectionDoesNotSuppressClientAndDevice(): void
    {
        $agent = UserAgent::parse(
            'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.96 '
            . 'Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        );

        $this->assertTrue($agent->isBot());
        $this->assertSame('Googlebot', $agent->bot()?->name);
        $this->assertTrue($agent->client()->isBrowser());
        $this->assertSame('smartphone', $agent->device()->type);
        $this->assertSame('Google', $agent->device()->brand);
    }

    public function testLibraryIsNotABrowser(): void
    {
        $agent = UserAgent::parse('curl/8.7.1');

        $this->assertSame('library', $agent->client()->type);
        $this->assertNull($agent->client()->code);
        $this->assertSame('curl', $agent->client()->name);
        $this->assertSame('8.7', $agent->client()->version);
        $this->assertFalse($agent->client()->isBrowser());
        $this->assertFalse($agent->isBot());
    }

    public function testNativeAppIsMobileApp(): void
    {
        $agent = UserAgent::parse('com.example.myapp/1.0.0 iPhone17,1 iOS/18.1');

        $this->assertSame('mobile app', $agent->client()->type);
        $this->assertSame('com.example.myapp', $agent->client()->name);
        $this->assertSame('1.0', $agent->client()->version);
    }

    public function testUnknownAndMalformedValuesAreSafe(): void
    {
        foreach (['', 'UNKNOWN', "\0\xff invalid user agent"] as $value) {
            $agent = UserAgent::parse($value);

            $this->assertSame($value, $agent->raw());
            $this->assertFalse($agent->operatingSystem()->isKnown());
            $this->assertFalse($agent->client()->isKnown());
            $this->assertFalse($agent->device()->isKnown());
            $this->assertFalse($agent->isBot());
        }
    }

    public function testCategoriesAreMemoized(): void
    {
        $agent = UserAgent::parse('Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0');

        $this->assertSame($agent->operatingSystem(), $agent->operatingSystem());
        $this->assertSame($agent->client(), $agent->client());
        $this->assertSame($agent->device(), $agent->device());
    }

    public function testNestedSerialization(): void
    {
        $agent = UserAgent::parse(
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        );

        $data = $agent->toArray();

        $this->assertSame('MAC', $data['os']['code']);
        $this->assertSame('CH', $data['client']['code']);
        $this->assertSame('desktop', $data['device']['type']);
        $this->assertNull($data['bot']);
    }
}
