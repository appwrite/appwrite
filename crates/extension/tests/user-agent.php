<?php

// Parity corpus for Utopia\UserAgent\UserAgent. Run it on the PHP class and on the utopia
// extension's (_APP_EXTENSION_LIBRARIES=user-agent), and diff stdout: every line must match.
// Stderr names the implementation that ran.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\UserAgent\UserAgent;

$internal = (new ReflectionClass(UserAgent::class))->isInternal();
fwrite(STDERR, 'Utopia\\UserAgent\\UserAgent: ' . ($internal ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP class would compare PHP with PHP and pass.
$requested = in_array('user-agent', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' user-agent, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

/** Warnings raised by the current case. */
$warnings = [];
set_error_handler(function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});

/** $value with every string that is not UTF-8 as its hex, so json_encode() cannot hide a byte. */
function exported(mixed $value): mixed
{
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8') ? $value : ['hex' => bin2hex($value)];
    }
    if (is_array($value)) {
        return array_map(exported(...), $value);
    }
    if (is_object($value)) {
        return ['class' => $value::class, 'properties' => exported(get_object_vars($value))];
    }

    return $value;
}

/** The observable outcome of $run: its value or exception, and the warnings it raised. */
function outcome(callable $run): array
{
    global $warnings;
    $warnings = [];
    try {
        $result = ['value' => exported($run())];
    } catch (Throwable $e) {
        $result = ['throws' => $e::class, 'message' => $e->getMessage()];
    }
    if ($warnings !== []) {
        $result['warnings'] = $warnings;
    }

    return $result;
}

function emit(string $case, array $result): void
{
    echo json_encode([exported($case), $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}

/** Everything UserAgent tells about $value, read the way Appwrite\Detector\Detector reads it. */
function inspect(string $value): array
{
    $agent = UserAgent::parse($value);
    $os = $agent->operatingSystem();
    $client = $agent->client();
    $device = $agent->device();
    $bot = $agent->bot();

    return [
        'raw' => $agent->raw(),
        'os' => $os,
        'client' => $client,
        'device' => $device,
        'bot' => $bot,
        'isBot' => $agent->isBot(),
        'known' => [$os->isKnown(), $client->isKnown(), $client->isBrowser(), $device->isKnown()],
        'same' => [$os === $agent->operatingSystem(), $client === $agent->client(), $device === $agent->device(), $bot === $agent->bot()],
        'array' => $agent->toArray(),
        'array first' => UserAgent::parse($value)->toArray(),
    ];
}

// Every string in the package's tests: the browsers, platforms, bots and Matomo cases they check.
$agents = [];
foreach (glob(__DIR__ . '/../../../packages/user-agent/tests/*.php') as $test) {
    foreach (token_get_all(file_get_contents($test)) as $token) {
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $agents[] = eval('return ' . $token[1] . ';');
        }
    }
}

// Agents Appwrite sees: SDKs, the CLI, the Console, server runtimes, HTTP libraries, previews.
array_push(
    $agents,
    'AppwriteCLI/6.2.3 (darwin 24.0.0; arm64)',
    'AppwriteNodeJSSDK/14.1.0 (Linux; x64)',
    'AppwritePythonSDK/7.0.0',
    'AppwriteDartSDK/13.0.0 (Linux; U; Android 14; SM-S918B) Flutter/3.24.0',
    'AppwriteFlutterSDK/13.0.0',
    'AppwriteSwiftSDK/7.0.0 (iOS 17.4; iPhone15,2)',
    'AppwriteKotlinSDK/6.0.0 (Android 14; Pixel 8)',
    'appwrite-console/1.6.0',
    'Appwrite-Server/1.6 Executor',
    'node',
    'node-fetch/1.0 (+https://github.com/bitinn/node-fetch)',
    'undici',
    'axios/1.7.2',
    'Dart/3.5 (dart:io)',
    'okhttp/4.12.0',
    'python-requests/2.32.3',
    'python-httpx/0.27.0',
    'Go-http-client/2.0',
    'curl/8.7.1',
    'Wget/1.21.4',
    'PostmanRuntime/7.39.0',
    'insomnia/2023.5.8',
    'Deno/1.44.0',
    'Bun/1.1.12',
    'GuzzleHttp/7',
    'Java/17.0.2',
    'Apache-HttpClient/4.5.14 (Java/17.0.2)',
    'CFNetwork/1494.0.7 Darwin/23.4.0',
    'MyApp/2 CFNetwork/1494.0.7 Darwin/23.4.0',
    'com.example.app/1.0 (iPhone; iOS 17.4; Scale/3.00)',
    'Expo/2.31.2 CFNetwork/1494.0.7 Darwin/23.4.0',
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)',
    'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
    'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
    'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
    'Discordbot/2.0; +https://discordapp.com',
    'Twitterbot/1.0',
    'TelegramBot (like TwitterBot)',
    'WhatsApp/2.23.20.0',
    'UptimeRobot/2.0',
    'Datadog Agent/7.50.0',
    'kube-probe/1.30',
    'ELB-HealthChecker/2.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 Edg/126.0.0.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
    'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0',
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.6478.71 Mobile Safari/537.36',
    'Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36 OPR/111.0.0.0',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/126.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Electron/31.0.0 Safari/537.36',
    'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.2; Trident/6.0)',
    'Mozilla/5.0 (Windows NT 10.0; Trident/7.0; rv:11.0) like Gecko',
    'Mozilla/5.0 (PlayStation; PlayStation 5/2.26) AppleWebKit/605.1.15 (KHTML, like Gecko)',
    'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/4.0 Chrome/76.0.3809.146 TV Safari/537.36',
    'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Linux; Android 9; AFTMM Build/PS7233) AppleWebKit/537.36 (KHTML, like Gecko) Silk/124.3.1 like Chrome/124.0.6367.179 Safari/537.36',
    // Edges: empty, whitespace, case, control bytes, bytes that are not UTF-8, long and repeated input.
    '',
    ' ',
    "\t\n",
    '0',
    'Mozilla',
    'Mozilla/5.0',
    'Mozilla/5.0 ()',
    'MOZILLA/5.0 (WINDOWS NT 10.0; WIN64; X64) APPLEWEBKIT/537.36 CHROME/126.0.0.0 SAFARI/537.36',
    'mozilla/5.0 (iphone; cpu iphone os 17_4 like mac os x) applewebkit/605.1.15 version/17.4 mobile/15e148 safari/604.1',
    "Mozilla/5.0 (Linux; Android 13; Pixel\xff 7 Build/X)",
    "com.ex\xffample/1.0 iPhone iOS/18.1",
    "Mozilla/5.0 (Windows NT 10.0\x00; Win64) Chrome/126",
    "\xff\xfe\xfd",
    'é中😀 Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X)',
    'bot',
    'Bot',
    'robot',
    'crawler',
    'spider',
    'abbot/1.0',
    'Googlebot',
    'not a bot',
    str_repeat('Mozilla/5.0 (Windows NT 10.0; Win64; x64) ', 200),
    str_repeat('(', 5000),
    str_repeat('a', 100000),
    'Mozilla/5.0 (Linux; Android 14; ' . str_repeat('X', 3000) . ' Build/UQ1A) Chrome/126.0.0.0 Mobile Safari/537.36',
);
$agents = array_values(array_unique($agents));

foreach ($agents as $agent) {
    emit($agent, outcome(fn () => inspect($agent)));
}

// Mutations of every agent: cut, case, swapped and dropped bytes. Seeded, so both runs see the same strings.
mt_srand(7503);
$mutations = 0;
foreach ($agents as $agent) {
    if (strlen($agent) < 8 || strlen($agent) > 1000) {
        continue;
    }
    $variants = [
        substr($agent, 0, intdiv(strlen($agent), 2)),
        substr($agent, intdiv(strlen($agent), 3)),
        strtolower($agent),
        strtoupper($agent),
        str_replace(['/', ';'], [' ', ','], $agent),
        str_replace(['.', '_'], ['_', '.'], $agent),
    ];
    for ($i = 0; $i < 4; $i++) {
        $bytes = $agent;
        $at = mt_rand(0, strlen($bytes) - 1);
        $variants[] = match ($i) {
            0 => substr_replace($bytes, '', $at, mt_rand(1, 6)),
            1 => substr_replace($bytes, chr(mt_rand(0, 255)), $at, 1),
            2 => substr_replace($bytes, $agents[mt_rand(0, count($agents) - 1)], $at, 0),
            3 => substr_replace($bytes, ' ' . mt_rand(0, 99) . '.' . mt_rand(0, 9) . ' ', $at, 0),
        };
    }
    foreach ($variants as $variant) {
        emit('mutation ' . $variant, outcome(fn () => inspect($variant)));
        $mutations++;
    }
}
fwrite(STDERR, count($agents) . ' agents, ' . $mutations . ' mutations' . PHP_EOL);

// What PHP converts a string parameter from outside strict_types, as Appwrite's callers run.
$stringable = new class () {
    public function __toString(): string
    {
        return 'Mozilla/5.0 (X11; Linux x86_64; rv:127.0) Gecko/20100101 Firefox/127.0';
    }
};
$arguments = ['int' => 5, 'float' => 1.5, 'true' => true, 'false' => false, 'stringable' => $stringable];
foreach ($arguments as $name => $argument) {
    emit('argument ' . $name, outcome(fn () => UserAgent::parse($argument)->toArray()));
}
foreach (['null' => null, 'array' => [], 'object' => new stdClass()] as $name => $argument) {
    emit('argument ' . $name, ['throws' => outcome(fn () => UserAgent::parse($argument))['throws'] ?? null]);
}

// The object itself: order of calls, comparison, copies, dumps and the class's shape.
$firefox = 'Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:47.0) Gecko/20100101 Firefox/47.0';
$googlebot = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
emit('isBot before bot', outcome(function () use ($googlebot) {
    $agent = UserAgent::parse($googlebot);

    return [$agent->isBot(), $agent->bot(), $agent->bot() === $agent->bot()];
}));
emit('equal fresh', outcome(fn () => UserAgent::parse($firefox) == UserAgent::parse($firefox)));
emit('equal different', outcome(fn () => UserAgent::parse($firefox) == UserAgent::parse($googlebot)));
emit('equal after detection', outcome(function () use ($firefox) {
    $detected = UserAgent::parse($firefox);
    $detected->client();

    return [$detected == UserAgent::parse($firefox), $detected == $detected];
}));
emit('equal both detected', outcome(function () use ($firefox) {
    $one = UserAgent::parse($firefox);
    $two = UserAgent::parse($firefox);
    $one->toArray();
    $two->toArray();

    return $one == $two;
}));
emit('identical', outcome(fn () => UserAgent::parse($firefox) === UserAgent::parse($firefox)));
emit('clone', outcome(function () use ($firefox) {
    $agent = UserAgent::parse($firefox);
    $client = $agent->client();
    $copy = clone $agent;

    return [$copy->client() === $client, $copy->raw(), $copy->device() === $agent->device(), $copy == $agent];
}));
emit('array cast', outcome(function () use ($firefox) {
    $agent = UserAgent::parse($firefox);
    $agent->operatingSystem();

    return array_map(fn ($value) => is_object($value) ? $value::class : $value, array_values((array) $agent));
}));
emit('array cast keys', outcome(fn () => array_map(bin2hex(...), array_keys((array) UserAgent::parse($firefox)))));
emit('print_r', outcome(fn () => print_r(UserAgent::parse($firefox), true)));
emit('var_export', outcome(fn () => var_export(UserAgent::parse($firefox), true)));
emit('json_encode', outcome(fn () => json_encode(UserAgent::parse($firefox))));
emit('read private', outcome(fn () => UserAgent::parse($firefox)->value));
emit('write property', outcome(function () use ($firefox) {
    $agent = UserAgent::parse($firefox);
    $agent->value = 'other';

    return $agent->raw();
}));
emit('dynamic property', outcome(function () use ($firefox) {
    $agent = UserAgent::parse($firefox);
    $agent->extra = 1;

    return $agent->extra;
}));
emit('var_dump', outcome(function () use ($firefox) {
    $agent = UserAgent::parse($firefox);
    $agent->toArray();
    ob_start();
    var_dump($agent);

    return preg_replace('/#\\d+ /', '# ', ob_get_clean());
}));
emit('new', outcome(fn () => new UserAgent($firefox)));
emit('instanceof', outcome(fn () => UserAgent::parse($firefox) instanceof UserAgent));
emit('class', outcome(fn () => UserAgent::parse($firefox)::class));
emit('final', outcome(fn () => (new ReflectionClass(UserAgent::class))->isFinal()));
emit('callable', outcome(fn () => array_map(UserAgent::parse(...), [$firefox])[0]->raw()));
emit('static call', outcome(fn () => call_user_func([UserAgent::class, 'parse'], $firefox)->client()->name));
$methods = (new ReflectionClass(UserAgent::class))->getMethods(ReflectionMethod::IS_PUBLIC);
usort($methods, fn ($a, $b) => strcmp($a->getName(), $b->getName()));
emit('methods', outcome(fn () => array_map(fn (ReflectionMethod $method) => [$method->getName(), $method->isStatic(), $method->getNumberOfParameters(), $method->getNumberOfRequiredParameters()], $methods)));
emit('detectors stay PHP', outcome(fn () => array_map(fn (string $class) => (new ReflectionClass($class))->isInternal(), [
    Utopia\UserAgent\Detection\BotDetector::class,
    Utopia\UserAgent\Detection\ClientDetector::class,
    Utopia\UserAgent\Detection\DeviceDetector::class,
    Utopia\UserAgent\Detection\OperatingSystemDetector::class,
    Utopia\UserAgent\OperatingSystem::class,
    Utopia\UserAgent\Client::class,
    Utopia\UserAgent\Device::class,
    Utopia\UserAgent\Bot::class,
])));
emit('detector', outcome(function () use ($firefox) {
    $detector = new Appwrite\Detector\Detector($firefox);

    return [$detector->getOS(), $detector->getClient(), $detector->getDevice()];
}));

// Known differences of the extension, each a waiver in crates/extension/README.md.
$waivers = [
    'argument null' => outcome(fn () => UserAgent::parse(null)),
    'argument array' => outcome(fn () => UserAgent::parse([])),
    'no argument' => outcome(fn () => UserAgent::parse()),
    'isset private' => outcome(fn () => isset(UserAgent::parse($firefox)->value)),
    'serialize' => outcome(fn () => unserialize(serialize(UserAgent::parse($firefox)))->raw()),
];
$expected = $internal ? [
    'argument null' => ['throws' => 'TypeError', 'message' => 'Invalid value given for argument `value`.'],
    'argument array' => ['throws' => 'TypeError', 'message' => 'Invalid value given for argument `value`.'],
    'no argument' => ['throws' => 'ArgumentCountError', 'message' => 'Utopia\\UserAgent\\UserAgent::parse() expects exactly 1 argument, 0 given'],
    'isset private' => ['value' => true],
    'serialize' => ['throws' => 'Exception', 'message' => "Serialization of 'Utopia\\UserAgent\\UserAgent' is not allowed"],
] : $waivers;
foreach ($expected as $name => $outcome) {
    if ($waivers[$name] !== $outcome) {
        fwrite(STDERR, "Waiver changed: {$name}: " . json_encode($waivers[$name], JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL);
        exit(1);
    }
}
fwrite(STDERR, count($expected) . ' waivers hold' . PHP_EOL);
