<?php

// Times the composing validators on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/validators-compose.php . <utopia.so> 5`.
// The leaves they wrap (Text, Integer) stay PHP in both runs, so the numbers are the composers' own.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Validator\AnyOf;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Globstar;
use Utopia\Validator\Integer;
use Utopia\Validator\JSON;
use Utopia\Validator\JSON\ArrayValidator;
use Utopia\Validator\JSON\FCM;
use Utopia\Validator\Multiple;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(Nullable::class))->isInternal();
$switches = array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: ''));
$requested = in_array('validators', $switches, true) && in_array('validators-compose', $switches, true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' validators-compose, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// What requests do: a route builds its validators, then checks a parameter (nullable names,
// lists of IDs and scopes, data documents, JSON headers, FCM credentials, deployment globs).
$nullable = new Nullable(new Text(128));
$list = new ArrayList(new Text(128), 100);
$anyOf = new AnyOf([new Integer(), new Text(16)]);
$multiple = new Multiple([new Text(36), new Text(0)]);
$assoc = new Assoc();
$json = new JSON();
$array = new ArrayValidator(1024);
$fcm = new FCM();
$globstar = new Globstar(['src/**', 'docs/**/*.md', '!src/test/**', 'README.md']);
$ids = array_map(fn ($i) => "document{$i}", range(1, 25));
$document = ['name' => 'Walter', 'age' => 42, 'tags' => ['a', 'b'], 'address' => ['city' => 'Tel Aviv']];
$account = json_encode([
    'type' => 'service_account', 'project_id' => 'project', 'private_key_id' => 'key-id',
    'private_key' => '-----BEGIN ' . "PRIVATE KEY-----\nMIIE\n-----END " . "PRIVATE KEY-----\n", 'client_email' => 'firebase@project.iam.gserviceaccount.com',
    'client_id' => '123', 'auth_uri' => 'https://accounts.google.com/o/oauth2/auth', 'token_uri' => 'https://oauth2.googleapis.com/token',
    'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
    'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase', 'universe_domain' => 'googleapis.com',
]);
$cases = [
    'new ArrayList' => fn () => new ArrayList(new Text(128), 100),
    'new Globstar' => fn () => new Globstar(['src/**', '!src/test/**']),
    'Nullable isValid null' => fn () => $nullable->isValid(null),
    'Nullable isValid string' => fn () => $nullable->isValid('Walter'),
    'ArrayList isValid 25' => fn () => $list->isValid($ids),
    'ArrayList getDescription' => fn () => $list->getDescription(),
    'AnyOf isValid' => fn () => $anyOf->isValid('short'),
    'Multiple isValid' => fn () => $multiple->isValid('unique()'),
    'Assoc isValid' => fn () => $assoc->isValid($document),
    'JSON isValid string' => fn () => $json->isValid('{"a":[1,2,{"b":null}]}'),
    'ArrayValidator isValid' => fn () => $array->isValid('["a","b","c"]'),
    'FCM isValid' => fn () => $fcm->isValid($account),
    'Globstar isValid' => fn () => $globstar->isValid('docs/guides/a/start.md'),
    'Globstar isValid excluded' => fn () => $globstar->isValid('src/test/unit/a.php'),
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
