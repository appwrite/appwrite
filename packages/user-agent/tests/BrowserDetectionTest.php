<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\UserAgent\UserAgent;

final class BrowserDetectionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, string, string|null}>
     */
    public static function browsers(): array
    {
        return [
            'Edge' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/126.0.0.0',
                'PS',
                'Microsoft Edge',
                'Blink',
                '126.0',
                '124.0.0.0',
            ],
            'Opera' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 OPR/110.0.0.0',
                'OP',
                'Opera',
                'Blink',
                '110.0',
                '124.0.0.0',
            ],
            'Samsung Internet' => [
                'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
                'SB',
                'Samsung Browser',
                'Blink',
                '25.0',
                '121.0.0.0',
            ],
            'Chrome iOS' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 '
                . 'Mobile/15E148 Safari/604.1',
                'CI',
                'Chrome Mobile iOS',
                'WebKit',
                '126.0',
                '605.1.15',
            ],
            'Firefox iOS' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/127.0 '
                . 'Mobile/15E148 Safari/605.1.15',
                'F1',
                'Firefox Mobile iOS',
                'WebKit',
                '127.0',
                '605.1.15',
            ],
            'Android WebView' => [
                'Mozilla/5.0 (Linux; Android 13; Pixel 6 Build/TQ3A; wv) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 '
                . 'Chrome/120.0.0.0 Mobile Safari/537.36',
                'CV',
                'Chrome Webview',
                'Blink',
                '120.0',
                '120.0.0.0',
            ],
            'Internet Explorer' => [
                'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko',
                'IE',
                'Internet Explorer',
                'Trident',
                '11.0',
                '7.0',
            ],
            'Opera Mini' => [
                'Opera/9.80 (Android; Opera Mini/36.2.2254/191.249; U; en) '
                . 'Presto/2.12.423 Version/12.16',
                'OI',
                'Opera Mini',
                'Presto',
                '36.2',
                '2.12.423',
            ],
            'Opera Mobile' => [
                'Mozilla/5.0 (Linux; Android 10; VOG-L29) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/104.0.0.0 Mobile Safari/537.36 OPR/64.3.3282.60839',
                'OM',
                'Opera Mobile',
                'Blink',
                '64.3',
                '104.0.0.0',
            ],
            'Brave' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Brave/120',
                'BR',
                'Brave',
                'Blink',
                '120',
                '120.0.0.0',
            ],
            'Vivaldi' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/117.0.0.0 Safari/537.36 Vivaldi/6.2',
                'VI',
                'Vivaldi',
                'Blink',
                '6.2',
                '117.0.0.0',
            ],
            'Yandex Browser' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/114.0.0.0 YaBrowser/23.7.1.1140 Safari/537.36',
                'YA',
                'Yandex Browser',
                'Blink',
                '23.7',
                '114.0.0.0',
            ],
            'UC Browser' => [
                'Mozilla/5.0 (Linux; U; Android 11; en-US; SM-M317F Build/RP1A.200720.012) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/100.0.4896.127 '
                . 'UCBrowser/15.5.0.1395 Mobile Safari/537.36',
                'UC',
                'UC Browser',
                'Blink',
                '15.5',
                '100.0.4896.127',
            ],
            'DuckDuckGo' => [
                'Mozilla/5.0 (Linux; Android 13; SM-G991B) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Version/4.0 Chrome/116.0.0.0 Mobile DuckDuckGo/5 Safari/537.36',
                'DD',
                'DuckDuckGo Privacy Browser',
                'Blink',
                '5',
                '116.0.0.0',
            ],
            'QQ Browser' => [
                'Mozilla/5.0 (Linux; U; Android 12; zh-cn; RMX3350 Build/SKQ1.211019.001) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/107.0.5304.105 '
                . 'MQQBrowser/13.6 Mobile Safari/537.36',
                'QQ',
                'QQ Browser',
                'Blink',
                '13.6',
                '107.0.5304.105',
            ],
            'Coc Coc' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) coc_coc_browser/112.0.174 Chrome/106.0.5249.174 Safari/537.36',
                'CC',
                'Coc Coc',
                'Blink',
                '112.0',
                '106.0.5249.174',
            ],
            'Whale' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/116.0.0.0 Whale/3.23.214.9 Safari/537.36',
                'WH',
                'Whale Browser',
                'Blink',
                '3.23',
                '116.0.0.0',
            ],
            'Huawei Browser' => [
                'Mozilla/5.0 (Linux; Android 10; ELS-NX9; HMSCore 6.6.0.311) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/99.0.4844.88 HuaweiBrowser/13.0.5.303 Mobile Safari/537.36',
                'HU',
                'Huawei Browser Mobile',
                'Blink',
                '13.0',
                '99.0.4844.88',
            ],
            'Amazon Silk' => [
                'Mozilla/5.0 (Linux; Android 9; KFMAWI) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Silk/104.5.1 like Chrome/104.0.5112.105 Safari/537.36',
                'MS',
                'Mobile Silk',
                'Blink',
                '104.5',
                '104.0.5112.105',
            ],
            'Amazon Silk (legacy WebKit)' => [
                'Mozilla/5.0 (Linux; U; en-US) AppleWebKit/533.16 (KHTML, like Gecko) '
                . 'Version/5.0 Safari/533.16 Silk/3.13',
                'MS',
                'Mobile Silk',
                'WebKit',
                '3.13',
                '533.16',
            ],
            'Firefox Focus' => [
                'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Version/4.0 Chrome/113.0.0.0 Mobile Safari/537.36 Focus/125.0',
                'FK',
                'Firefox Focus',
                'Blink',
                '125.0',
                '113.0.0.0',
            ],
        ];
    }

    #[DataProvider('browsers')]
    public function testBrowser(
        string $userAgent,
        string $code,
        string $name,
        string $engine,
        string $version,
        ?string $engineVersion,
    ): void {
        $client = UserAgent::parse($userAgent)->client();

        $this->assertSame('browser', $client->type);
        $this->assertSame($code, $client->code);
        $this->assertSame($name, $client->name);
        $this->assertSame($engine, $client->engine);
        $this->assertSame($version, $client->version);
        $this->assertSame($engineVersion, $client->engineVersion);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function libraries(): array
    {
        return [
            'curl' => ['curl/8.7.1', 'curl', '8.7'],
            'Guzzle' => ['GuzzleHttp/7.8', 'Guzzle', '7.8'],
            'Go' => ['Go-http-client/2.0', 'Go-http-client', '2.0'],
            'Java' => ['Java/17.0.2', 'Java', '17.0'],
            'Axios' => ['axios/1.6.2', 'Axios', '1.6'],
            'Node Fetch' => ['node-fetch/1.0 (+https://github.com/bitinn/node-fetch)', 'Node Fetch', '1.0'],
            'aiohttp' => ['Python/3.11 aiohttp/3.9.1', 'aiohttp', '3.9'],
            'HTTPie' => ['HTTPie/3.2.2', 'HTTPie', '3.2'],
            'Apache HTTP Client' => ['Apache-HttpClient/4.5.13 (Java/1.8.0_292)', 'Apache HTTP Client', '4.5'],
        ];
    }

    #[DataProvider('libraries')]
    public function testLibrary(string $userAgent, string $name, string $version): void
    {
        $client = UserAgent::parse($userAgent)->client();

        $this->assertSame('library', $client->type);
        $this->assertSame($name, $client->name);
        $this->assertSame($version, $client->version);
    }
}
