<?php

// Times Utopia\UserAgent\UserAgent on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/user-agent.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Appwrite\Detector\Detector;
use Utopia\UserAgent\UserAgent;

// A run meant for the extension that gets the PHP class would time PHP against PHP.
$internal = (new ReflectionClass(UserAgent::class))->isInternal();
$requested = in_array('user-agent', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' user-agent, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// What a session or usage event does: the OS, client and device of one agent, through Appwrite's Detector.
$detect = function (string $agent): void {
    $detector = new Detector($agent);
    $detector->getOS();
    $detector->getClient();
    $detector->getDevice();
};
$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';
$iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';
$android = 'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.71 Mobile Safari/537.36';
$sdk = 'AppwriteNodeJSSDK/14.1.0 (Linux; x64)';
$googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
$gptbot = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';
$uptime = 'Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)';
$crawler = 'AcmeCrawler/1.0 (+https://acme.example/crawler)';
$cases = [
    'Detector desktop Chrome' => fn () => $detect($chrome),
    'Detector iPhone Safari' => fn () => $detect($iphone),
    'Detector Android Chrome' => fn () => $detect($android),
    'Detector SDK' => fn () => $detect($sdk),
    'isBot browser' => fn () => UserAgent::parse($chrome)->isBot(),
    'isBot Googlebot' => fn () => UserAgent::parse($googlebot)->isBot(),
    'isBot GPTBot' => fn () => UserAgent::parse($gptbot)->isBot(),
    'isBot UptimeRobot' => fn () => UserAgent::parse($uptime)->isBot(),
    'isBot generic crawler' => fn () => UserAgent::parse($crawler)->isBot(),
    'toArray Android Chrome' => fn () => UserAgent::parse($android)->toArray(),
];
$n = 20000;
$out = [];
foreach ($cases as $name => $fn) {
    for ($i = 0; $i < 500; $i++) {
        $fn();
    }
    $runs = [];
    for ($r = 0; $r < 5; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $fn();
        }
        $runs[] = (hrtime(true) - $t) / $n;
    }
    sort($runs);
    $out[$name] = $runs[2];
}
$out['peak memory MB'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
