<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Tests;

use DeviceDetector\DeviceDetector as MatomoDeviceDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\UserAgent\UserAgent;

final class MatomoCompatibilityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function referenceProfiles(): array
    {
        return [
            'Firefox on Windows' => [
                'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0',
            ],
            'Mobile Safari on iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 '
                . 'Mobile/15E148 Safari/604.1',
            ],
            'Chrome Mobile on Pixel' => [
                'Mozilla/5.0 (Linux; Android 13; Pixel 7 Pro Build/TQ3A.230805.001) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 '
                . 'Mobile Safari/537.36',
            ],
            'Chrome on Mac' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            ],
            'Safari on iPad' => [
                'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 '
                . 'Mobile/15E148 Safari/604.1',
            ],
            'Firefox on Debian' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:102.0) '
                . 'Gecko/20100101 Firefox/102.0 Debian/102.0',
            ],
            'Yandex Browser on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/114.0.0.0 YaBrowser/23.7.1.1140 Safari/537.36',
            ],
        ];
    }

    #[DataProvider('referenceProfiles')]
    public function testCoreFieldsMatchMatomo64(string $userAgent): void
    {
        $matomo = new MatomoDeviceDetector($userAgent);
        $matomo->skipBotDetection();
        $matomo->parse();

        $os = $matomo->getOs();
        $client = $matomo->getClient();
        $agent = UserAgent::parse($userAgent);

        $this->assertSame($this->nullable($os['short_name'] ?? null), $agent->operatingSystem()->code);
        $this->assertSame($this->nullable($os['name'] ?? null), $agent->operatingSystem()->name);
        $this->assertSame($this->nullable($os['version'] ?? null), $agent->operatingSystem()->version);
        $this->assertSame($this->nullable($client['type'] ?? null), $agent->client()->type);
        $this->assertSame($this->nullable($client['short_name'] ?? null), $agent->client()->code);
        $this->assertSame($this->nullable($client['name'] ?? null), $agent->client()->name);
        $this->assertSame($this->nullable($client['version'] ?? null), $agent->client()->version);
        $this->assertSame($this->nullable($client['engine'] ?? null), $agent->client()->engine);
        $this->assertSame($this->nullable($client['engine_version'] ?? null), $agent->client()->engineVersion);
        $this->assertSame($this->nullable($matomo->getDeviceName()), $agent->device()->type);
        $this->assertSame($this->nullable($matomo->getBrandName()), $agent->device()->brand);
        $this->assertSame($this->nullable($matomo->getModel()), $agent->device()->model);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function botProfiles(): array
    {
        return [
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/537.36'],
        ];
    }

    #[DataProvider('botProfiles')]
    public function testBotDecisionMatchesMatomo64(string $userAgent): void
    {
        $matomo = new MatomoDeviceDetector($userAgent);
        $matomo->parse();

        $this->assertSame($matomo->isBot(), UserAgent::parse($userAgent)->isBot());
    }

    private function nullable(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
