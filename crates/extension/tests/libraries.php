<?php

declare(strict_types=1);

// Which classes the utopia extension registers, for utopia.libraries (see README). No Composer
// autoloader is loaded, so a class exists only if the extension registered it.
//
// With no arguments, prints the extension's classes, sorted, one per line: run.sh diffs that with
// utopia.libraries unset against libraries.expected. Otherwise every argument is a check:
//   +Class   the extension registered Class
//   -Class   Class does not exist
//   ini=V    ini_get('utopia.libraries') is V
// It prints `ok` and exits 0, or names each failed check on stderr and exits 1.

if ($argc === 1) {
    $classes = (new ReflectionExtension('utopia'))->getClassNames();
    sort($classes);
    echo implode(PHP_EOL, $classes), PHP_EOL;
    exit(0);
}

$failed = [];
foreach (array_slice($argv, 1) as $check) {
    if (str_starts_with($check, 'ini=')) {
        $value = ini_get('utopia.libraries');
        if ($value !== substr($check, 4)) {
            $failed[] = "ini_get('utopia.libraries') is " . var_export($value, true) . ', not ' . var_export(substr($check, 4), true);
        }
        continue;
    }
    $class = substr($check, 1);
    $exists = class_exists($class, false) || interface_exists($class, false);
    $failed[] = match ($check[0]) {
        '+' => !$exists ? "$class is not registered" : (!(new ReflectionClass($class))->isInternal() ? "$class is not internal" : null),
        '-' => $exists ? "$class is registered" : null,
        default => "unknown check $check",
    };
}

$failed = array_filter($failed);
if ($failed !== []) {
    fwrite(STDERR, 'utopia.libraries=' . ini_get('utopia.libraries') . ': ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}
echo 'ok', PHP_EOL;
