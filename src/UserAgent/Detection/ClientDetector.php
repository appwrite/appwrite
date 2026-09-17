<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Detection;

use Utopia\UserAgent\Client;

final class ClientDetector
{
    public static function detect(string $userAgent): Client
    {
        if ($userAgent === '') {
            return new Client();
        }

        $client = self::edge($userAgent)
            ?? self::opera($userAgent)
            ?? self::samsung($userAgent)
            ?? self::chromeIos($userAgent)
            ?? self::firefoxIos($userAgent)
            ?? self::derivative($userAgent)
            ?? self::androidWebView($userAgent)
            ?? self::chrome($userAgent)
            ?? self::firefox($userAgent)
            ?? self::safari($userAgent)
            ?? self::internetExplorer($userAgent)
            ?? self::library($userAgent)
            ?? self::mobileApp($userAgent);

        return $client ?? new Client();
    }

    /**
     * Chromium-based browsers that ship their own token. These must resolve
     * before the generic Chrome and Android WebView rules, because their
     * user-agent strings also carry a `Chrome/` token (and sometimes the
     * `Version/4.0` WebView marker). The rendering engine is Blink and its
     * version follows the embedded Chrome token.
     */
    private static function derivative(string $userAgent): ?Client
    {
        /** @var array<string, array{string, string}> $blink */
        $blink = [
            '/coc_coc_browser\/([0-9.]+)/i' => ['CC', 'Coc Coc'],
            '/Vivaldi\/([0-9.]+)/i' => ['VI', 'Vivaldi'],
            '/YaBrowser\/([0-9.]+)/i' => ['YA', 'Yandex Browser'],
            '/Brave\/([0-9.]+)/i' => ['BR', 'Brave'],
            '/Whale\/([0-9.]+)/i' => ['WH', 'Whale Browser'],
            '/UCBrowser\/([0-9.]+)/i' => ['UC', 'UC Browser'],
            '/(?:MQQBrowser|QQBrowser)\/([0-9.]+)/i' => ['QQ', 'QQ Browser'],
            '/DuckDuckGo\/([0-9.]+)/i' => ['DD', 'DuckDuckGo Privacy Browser'],
            '/Silk\/([0-9.]+)/i' => ['MS', 'Mobile Silk'],
        ];

        foreach ($blink as $pattern => [$code, $name]) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return self::derivativeClient($userAgent, $code, $name, $matches[1]);
            }
        }

        // Firefox Focus reports as Blink only when it embeds a Chrome token.
        if (preg_match('/Focus\/([0-9.]+)/i', $userAgent, $matches) === 1
            && self::tokenVersion($userAgent, 'Chrome') !== null) {
            return self::derivativeClient($userAgent, 'FK', 'Firefox Focus', $matches[1]);
        }

        // Huawei Browser distinguishes its mobile build by name.
        if (preg_match('/HuaweiBrowser\/([0-9.]+)/i', $userAgent, $matches) === 1) {
            $mobile = stripos($userAgent, 'Mobile') !== false;

            return self::derivativeClient(
                $userAgent,
                $mobile ? 'HU' : 'HP',
                $mobile ? 'Huawei Browser Mobile' : 'Huawei Browser',
                $matches[1],
            );
        }

        return null;
    }

    /**
     * Build a client for a Chromium-based derivative. When the user-agent
     * carries a `Chrome/` token the engine is Blink at that version; the older
     * Amazon Silk builds carry no Chrome token, so their engine is the embedded
     * WebKit instead. The engine is left unknown when neither token is present.
     */
    private static function derivativeClient(string $userAgent, string $code, string $name, string $version): Client
    {
        $chrome = self::tokenVersion($userAgent, 'Chrome');
        if ($chrome !== null) {
            return new Client('browser', $code, $name, self::displayVersion($version), 'Blink', $chrome);
        }

        $webKit = self::tokenVersion($userAgent, 'AppleWebKit');
        if ($webKit !== null) {
            return new Client('browser', $code, $name, self::displayVersion($version), 'WebKit', $webKit);
        }

        return new Client('browser', $code, $name, self::displayVersion($version));
    }

    private static function edge(string $userAgent): ?Client
    {
        if (preg_match('/(?:EdgA|EdgiOS|Edg|Edge)\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $engineVersion = self::tokenVersion($userAgent, 'Chrome') ?? self::version($matches[1]);

        return new Client('browser', 'PS', 'Microsoft Edge', self::displayVersion($matches[1]), 'Blink', $engineVersion);
    }

    private static function opera(string $userAgent): ?Client
    {
        if (preg_match('/Opera Mini\/([0-9.]+)/i', $userAgent, $matches) === 1) {
            return new Client(
                'browser',
                'OI',
                'Opera Mini',
                self::displayVersion($matches[1]),
                'Presto',
                self::tokenVersion($userAgent, 'Presto'),
            );
        }

        if (preg_match('/(?:OPR|Opera)\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $engineVersion = self::tokenVersion($userAgent, 'Chrome') ?? self::version($matches[1]);
        $mobile = stripos($userAgent, 'Mobile') !== false || stripos($userAgent, 'Opera Mobi') !== false;

        return new Client(
            'browser',
            $mobile ? 'OM' : 'OP',
            $mobile ? 'Opera Mobile' : 'Opera',
            self::displayVersion($matches[1]),
            'Blink',
            $engineVersion,
        );
    }

    private static function samsung(string $userAgent): ?Client
    {
        if (preg_match('/SamsungBrowser\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $engineVersion = self::tokenVersion($userAgent, 'Chrome') ?? self::version($matches[1]);

        return new Client('browser', 'SB', 'Samsung Browser', self::displayVersion($matches[1]), 'Blink', $engineVersion);
    }

    private static function chromeIos(string $userAgent): ?Client
    {
        if (preg_match('/CriOS\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        return new Client(
            'browser',
            'CI',
            'Chrome Mobile iOS',
            self::displayVersion($matches[1]),
            'WebKit',
            self::tokenVersion($userAgent, 'AppleWebKit'),
        );
    }

    private static function firefoxIos(string $userAgent): ?Client
    {
        if (preg_match('/FxiOS\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        return new Client(
            'browser',
            'F1',
            'Firefox Mobile iOS',
            self::displayVersion($matches[1]),
            'WebKit',
            self::tokenVersion($userAgent, 'AppleWebKit'),
        );
    }

    private static function androidWebView(string $userAgent): ?Client
    {
        if (!str_contains($userAgent, '; wv)') && stripos($userAgent, 'Version/4.0 Chrome/') === false) {
            return null;
        }

        $engineVersion = self::tokenVersion($userAgent, 'Chrome');
        if ($engineVersion === null) {
            return null;
        }

        return new Client('browser', 'CV', 'Chrome Webview', self::displayVersion($engineVersion), 'Blink', $engineVersion);
    }

    private static function chrome(string $userAgent): ?Client
    {
        if (preg_match('/(?:Chrome|Chromium|HeadlessChrome)\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $engineVersion = self::version($matches[1]);
        $mobile = stripos($userAgent, 'Mobile') !== false || stripos($userAgent, 'Android') !== false;

        return new Client(
            'browser',
            $mobile ? 'CM' : 'CH',
            $mobile ? 'Chrome Mobile' : 'Chrome',
            self::displayVersion($matches[1]),
            'Blink',
            $engineVersion,
        );
    }

    private static function firefox(string $userAgent): ?Client
    {
        if (preg_match('/Firefox\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $version = self::displayVersion($matches[1]);
        $mobile = stripos($userAgent, 'Mobile') !== false || stripos($userAgent, 'Android') !== false;

        return new Client(
            'browser',
            $mobile ? 'FM' : 'FF',
            $mobile ? 'Firefox Mobile' : 'Firefox',
            $version,
            'Gecko',
            $version,
        );
    }

    private static function safari(string $userAgent): ?Client
    {
        if (stripos($userAgent, 'Safari/') === false || stripos($userAgent, 'AppleWebKit/') === false) {
            return null;
        }

        $version = self::tokenVersion($userAgent, 'Version');
        $mobile = stripos($userAgent, 'Mobile/') !== false
            && (stripos($userAgent, 'iPhone') !== false
                || stripos($userAgent, 'iPad') !== false
                || stripos($userAgent, 'iPod') !== false);

        return new Client(
            'browser',
            $mobile ? 'MF' : 'SF',
            $mobile ? 'Mobile Safari' : 'Safari',
            $version,
            'WebKit',
            self::tokenVersion($userAgent, 'AppleWebKit'),
        );
    }

    private static function internetExplorer(string $userAgent): ?Client
    {
        if (preg_match('/(?:MSIE |Trident\/.*?rv:)([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        return new Client(
            'browser',
            'IE',
            'Internet Explorer',
            self::version($matches[1]),
            'Trident',
            self::tokenVersion($userAgent, 'Trident'),
        );
    }

    private static function library(string $userAgent): ?Client
    {
        $libraries = [
            '/curl\/([0-9.]+)/i' => 'curl',
            '/Wget\/([0-9.]+)/i' => 'Wget',
            '/PostmanRuntime\/([0-9.]+)/i' => 'Postman Runtime',
            '/okhttp\/([0-9.]+)/i' => 'OkHttp',
            '/Dart\/([0-9.]+)/i' => 'Dart',
            '/GuzzleHttp\/([0-9.]+)/i' => 'Guzzle',
            '/python-requests\/([0-9.]+)/i' => 'Python Requests',
            '/Python-urllib\/?([0-9.]*)/i' => 'Python urllib',
            '/aiohttp\/([0-9.]+)/i' => 'aiohttp',
            '/Go-http-client\/([0-9.]+)/i' => 'Go-http-client',
            '/node-fetch\/([0-9.]+)/i' => 'Node Fetch',
            '/axios\/([0-9.]+)/i' => 'Axios',
            '/HTTPie\/([0-9.]+)/i' => 'HTTPie',
            '/Apache-HttpClient\/([0-9.]+)/i' => 'Apache HTTP Client',
            '/Java-http-client\/([0-9.]+)/i' => 'Java HTTP Client',
            '/Java\/([0-9._]+)/i' => 'Java',
            '/got\/([0-9.]+)/i' => 'got',
        ];

        foreach ($libraries as $pattern => $name) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return new Client('library', null, $name, self::displayVersion($matches[1]));
            }
        }

        return null;
    }

    private static function mobileApp(string $userAgent): ?Client
    {
        // Native Flutter iOS clients send `<bundle identifier>/<version> <machine> iOS/<version>`.
        $tokens = explode(' ', $userAgent);
        if (\count($tokens) !== 3 || !str_starts_with($tokens[2], 'iOS/')) {
            return null;
        }

        [$name, $version] = array_pad(explode('/', $tokens[0], 2), 2, '');
        if (!str_contains($name, '.') || $version === '' || trim($version, '.0123456789') !== '') {
            return null;
        }

        return new Client('mobile app', null, $name, self::displayVersion($version));
    }

    private static function tokenVersion(string $userAgent, string $token): ?string
    {
        if (preg_match('/' . preg_quote($token, '/') . '\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        return self::version($matches[1]);
    }

    private static function version(string $version): string
    {
        return trim(str_replace('_', '.', $version), '.-');
    }

    private static function displayVersion(string $version): string
    {
        return implode('.', \array_slice(explode('.', self::version($version)), 0, 2));
    }
}
