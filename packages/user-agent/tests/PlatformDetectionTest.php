<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\UserAgent\UserAgent;

final class PlatformDetectionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, ?string}>
     */
    public static function platforms(): array
    {
        return [
            'Chrome OS' => [
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 Chrome/101.0.0.0 Safari/537.36',
                'COS',
                'Chrome OS',
                'desktop',
            ],
            'Ubuntu' => [
                'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
                'UBT',
                'Ubuntu',
                'desktop',
            ],
            'Windows Phone' => [
                'Mozilla/5.0 (Windows Phone 10.0; Android 6.0.1; Microsoft; Lumia 950 XL)',
                'WPH',
                'Windows Phone',
                'smartphone',
            ],
            'Tizen TV' => [
                'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36 TV Safari/537.36',
                'TIZ',
                'Tizen',
                'tv',
            ],
            'iPadOS' => [
                'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 '
                . '(KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
                'IPA',
                'iPadOS',
                'tablet',
            ],
            'HarmonyOS' => [
                'Mozilla/5.0 (Phone; OpenHarmony 5.0) ArkWeb/4.1.6.1 Mobile',
                'OHS',
                'OpenHarmony',
                null,
            ],
            'Debian' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:102.0) Gecko/20100101 Firefox/102.0 Debian/102.0',
                'DEB',
                'Debian',
                'desktop',
            ],
            'Fedora' => [
                'Mozilla/5.0 (X11; Fedora; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0',
                'FED',
                'Fedora',
                'desktop',
            ],
            'Arch Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0 Arch Linux',
                'ARL',
                'Arch Linux',
                'desktop',
            ],
            'Linux Mint' => [
                'Mozilla/5.0 (X11; Linux Mint; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0',
                'MIN',
                'Mint',
                'desktop',
            ],
            'Fire OS' => [
                'Mozilla/5.0 (Linux; Android 9; KFMAWI) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Silk/104.5.1 like Chrome/104.0.5112.105 Safari/537.36',
                'FIR',
                'Fire OS',
                'tablet',
            ],
            'webOS TV' => [
                'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/79.0.3945.79 Safari/537.36 WebAppManager',
                'WOS',
                'webOS',
                'tv',
            ],
            'Sailfish OS' => [
                'Mozilla/5.0 (Mobile; rv:78.0; Sailfish; Jolla) Gecko/78.0 Firefox/78.0',
                'SAF',
                'Sailfish OS',
                null,
            ],
        ];
    }

    #[DataProvider('platforms')]
    public function testPlatform(string $userAgent, string $code, string $name, ?string $deviceType): void
    {
        $agent = UserAgent::parse($userAgent);

        $this->assertSame($code, $agent->operatingSystem()->code);
        $this->assertSame($name, $agent->operatingSystem()->name);
        $this->assertSame($deviceType, $agent->device()->type);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function specialDevices(): array
    {
        return [
            'Xbox' => ['Mozilla/5.0 (Xbox One; Xbox One OS 10.0)', 'console', 'Microsoft'],
            'PlayStation' => ['Mozilla/5.0 (PlayStation 5/1.00)', 'console', 'Sony'],
            'Nintendo' => ['Mozilla/5.0 (Nintendo Switch; WifiWebAuthApplet)', 'console', 'Nintendo'],
            'Kindle' => ['Mozilla/5.0 (Linux; U; en-US) AppleWebKit/533.16 Silk/3.13 Safari/533.16', 'tablet', 'Amazon'],
            'BlackBerry' => ['Mozilla/5.0 (BB10; Touch) AppleWebKit/537.35 Mobile Safari/537.35', 'smartphone', 'BlackBerry'],
            'Realme' => [
                'Mozilla/5.0 (Linux; Android 13; RMX3630) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/116.0.0.0 Mobile Safari/537.36',
                'smartphone',
                'Realme',
            ],
            'Asus' => [
                'Mozilla/5.0 (Linux; Android 13; ASUS_AI2205) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/116.0.0.0 Mobile Safari/537.36',
                'smartphone',
                'Asus',
            ],
            'Tecno' => [
                'Mozilla/5.0 (Linux; Android 12; TECNO KI5k) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/104.0.0.0 Mobile Safari/537.36',
                'smartphone',
                'Tecno',
            ],
            'webOS LG TV' => [
                'Mozilla/5.0 (Web0S; Linux/SmartTV) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/79.0.3945.79 Safari/537.36 WebAppManager',
                'tv',
                'LG',
            ],
            'Honor named model' => [
                'Mozilla/5.0 (Linux; Android 13; Honor 90 Build/HONORREA-N31) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36',
                'smartphone',
                'Honor',
            ],
            'Honor uppercase brand' => [
                'Mozilla/5.0 (Linux; Android 12; HONOR WKG-LX9) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/104.0.0.0 Mobile Safari/537.36',
                'smartphone',
                'Honor',
            ],
            'honor word is not a brand' => [
                'Mozilla/5.0 (Linux; Android 13; SM-G991B Build/InHonorOfRelease) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/116.0.0.0 Mobile Safari/537.36',
                'smartphone',
                'Samsung',
            ],
        ];
    }

    #[DataProvider('specialDevices')]
    public function testSpecialDevice(string $userAgent, string $type, string $brand): void
    {
        $device = UserAgent::parse($userAgent)->device();

        $this->assertSame($type, $device->type);
        $this->assertSame($brand, $device->brand);
    }
}
