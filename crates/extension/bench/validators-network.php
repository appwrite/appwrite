<?php

// Times the network leaf validators on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/validators-network.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Validator\Domain;
use Utopia\Validator\Host;
use Utopia\Validator\Hostname;
use Utopia\Validator\IP;
use Utopia\Validator\Phone;
use Utopia\Validator\URL;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(URL::class))->isInternal();
$switches = array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: ''));
$requested = in_array('validators', $switches, true) && in_array('validators-network', $switches, true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' validators-network, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// What requests do: a route builds its validators, then checks a parameter (redirect URLs,
// webhook URLs, custom domains, platform hostnames, client IPs, phone numbers).
$url = new URL();
$redirect = new URL(['https', 'http', 'com.example.app'], false, true, true, true);
$schemes = new URL(['http', 'https']);
$hostname = new Hostname(['localhost', '*.appwrite.io', 'example.com']);
$domain = new Domain([Domain::createRestriction('appwrite.network', 3, ['api.', 'console.'])]);
$host = new Host(['appwrite.io', '*.appwrite.io']);
$ip = new IP();
$ipv6 = new IP(IP::V6);
$phone = new Phone(false, true);
$cases = [
    'new URL' => fn () => new URL(['http', 'https']),
    'new Domain' => fn () => new Domain([Domain::createRestriction('appwrite.network', 3)]),
    'URL isValid' => fn () => $url->isValid('https://cloud.appwrite.io/v1/account?project=abc#top'),
    'URL isValid schemes' => fn () => $schemes->isValid('https://example.com/webhook'),
    'URL isValid redirect' => fn () => $redirect->isValid('https://app.example.com/auth/callback?state=1'),
    'URL isValid private-use' => fn () => $redirect->isValid('com.example.app:/oauth2redirect'),
    'URL isValid IRI' => fn () => $url->isValid('https://exämple.com/čšž'),
    'Hostname isValid wildcard' => fn () => $hostname->isValid('console.appwrite.io'),
    'Domain isValid' => fn () => $domain->isValid('my-site.appwrite.network'),
    'Host isValid' => fn () => $host->isValid('https://cloud.appwrite.io/console'),
    'IP isValid v4' => fn () => $ip->isValid('203.0.113.42'),
    'IP isValid v6' => fn () => $ipv6->isValid('2001:db8:85a3::8a2e:370:7334'),
    'Phone isValid' => fn () => $phone->isValid('%2B14155552671'),
    'URL getDescription' => fn () => $redirect->getDescription(),
];
$n = 20000;
$out = [];
foreach ($cases as $name => $fn) {
    for ($i = 0; $i < 1000; $i++) {
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
