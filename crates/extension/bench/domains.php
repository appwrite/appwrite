<?php

// Times Utopia\Domains\Domain on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/domains.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Domains\Domain;

// A run meant for the extension that gets the PHP class would time PHP against PHP.
$internal = (new ReflectionClass(Domain::class))->isInternal();
$requested = in_array('domains', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' domains, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// The first Domain reads the list (data.php for PHP, the embedded list for Rust).
$boot = hrtime(true);
(new Domain('example.com'))->isKnown();
$boot = hrtime(true) - $boot;

// What requests do: the certificate check in app/controllers/general.php, origin and
// custom-domain checks, and a domain's parts.
$cases = [
    'new' => fn () => new Domain('fra.cloud.appwrite.io'),
    'certificate check' => function () {
        $domain = new Domain('myapp.example.co.uk');

        return empty($domain->get()) || !$domain->isKnown() || $domain->isTest();
    },
    'getName' => fn () => (new Domain('sub.project.appwrite.network'))->getName(),
    'getApex' => fn () => (new Domain('www.example.com'))->getApex(),
    'getRegisterable' => fn () => (new Domain('blog.example.co.uk'))->getRegisterable(),
    'wildcard rule' => fn () => (new Domain('a.b.c.compute.amazonaws.com'))->getRegisterable(),
    'unknown TLD' => fn () => (new Domain('app.internal-host'))->isKnown(),
    'all getters' => function () {
        $domain = new Domain('Shop.Example.CO.UK');

        return [$domain->getTLD(), $domain->getSuffix(), $domain->getName(), $domain->getSub(), $domain->isICANN()];
    },
];
$n = 50000;
$out = ['first use (ns, once)' => $boot];
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
