<?php

declare(strict_types=1);

use DeviceDetector\DeviceDetector;
use Utopia\UserAgent\UserAgent;

require __DIR__ . '/../vendor/autoload.php';

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 13; Pixel 7 Pro Build/TQ3A.230805.001) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Linux; Android 12; SM-T970 Build/SP1A.210812.016) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0',
    'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 YaBrowser/23.7.1.1140 Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64; rv:102.0) Gecko/20100101 Firefox/102.0 Debian/102.0',
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
    'PostmanRuntime/7.43.0',
    'curl/8.7.1',
    'UNKNOWN',
];

$iterations = (int) (getenv('BENCH_ITERATIONS') ?: 50);

/**
 * @param callable(string): void $callback
 */
$benchmark = static function (callable $callback) use ($userAgents, $iterations): float {
    foreach ($userAgents as $userAgent) {
        $callback($userAgent);
    }

    $started = hrtime(true);
    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        foreach ($userAgents as $userAgent) {
            $callback($userAgent);
        }
    }

    $seconds = (hrtime(true) - $started) / 1_000_000_000;

    return ($iterations * count($userAgents)) / $seconds;
};

$utopia = $benchmark(static function (string $userAgent): void {
    $agent = UserAgent::parse($userAgent);
    $agent->operatingSystem();
    $agent->client();
    $agent->device();
});

$matomo = $benchmark(static function (string $userAgent): void {
    $detector = new DeviceDetector($userAgent);
    $detector->skipBotDetection();
    $detector->parse();
    $detector->getOs();
    $detector->getClient();
    $detector->getDeviceName();
    $detector->getBrandName();
    $detector->getModel();
});

$ratio = $utopia / $matomo;
$report = sprintf(
    "| Parser | Operations/second | Relative |\n"
    . "| --- | ---: | ---: |\n"
    . "| Utopia User Agent | %s | %.2fx |\n"
    . "| Matomo DeviceDetector 6.4 | %s | 1.00x |\n",
    number_format($utopia),
    $ratio,
    number_format($matomo),
);

echo $report;

$reportPath = getenv('BENCH_REPORT');
if (is_string($reportPath) && $reportPath !== '') {
    file_put_contents($reportPath, "### user-agent\n\n{$report}\n", FILE_APPEND);
}
