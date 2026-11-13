<?php

// Times Utopia\Locale\Locale on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/locale.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Locale\Locale;

// What app/init/locales.php does at boot.
$translations = __DIR__ . '/../../../app/config/locale/translations';
$files = glob("{$translations}/*.json");
$boot = hrtime(true);
Locale::$exceptions = false;
foreach ($files as $path) {
    Locale::setLanguageFromJSON(basename($path, '.json'), $path);
}
$boot = (hrtime(true) - $boot) / count($files);

// What a request does: a locale with an English fallback, then texts.
$locale = (new Locale('af'))->setFallback('en');
$placeholders = ['project' => 'Demo', 'user' => 'Ana', 'redirect' => 'https://cloud.appwrite.io/verify?userId=1&secret=abc'];
$cases = [
    'new + setFallback' => fn () => (new Locale('af'))->setFallback('en'),
    'getText hit' => fn () => $locale->getText('emails.verification.subject'),
    'getText fallback' => fn () => $locale->getText('emails.verification.preview'),
    'getText missing' => fn () => $locale->getText('missing.key'),
    'getText 3 placeholders' => fn () => $locale->getText('emails.verification.hello', placeholders: $placeholders),
    'getTranslations' => fn () => $locale->getTranslations(),
];
$n = 100000;
$out = ['setLanguageFromJSON (per file)' => $boot];
foreach ($cases as $name => $fn) {
    for ($i = 0; $i < 2000; $i++) {
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
