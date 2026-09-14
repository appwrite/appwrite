<?php

declare(strict_types=1);

// Compare native PHPUnit --list-tests-xml output with PHPUnit/ParaTest JUnit output.
// Usage: php tests/tools/results.php expected.xml junit.xml [--fail-on-skipped]
$arguments = $_SERVER['argv'] ?? [];
try {
    if (count($arguments) < 3) {
        throw new RuntimeException('Usage: results.php expected.xml junit.xml [--fail-on-skipped]');
    }
    $expected = simplexml_load_file($arguments[1], options: LIBXML_NONET);
    $actual = simplexml_load_file($arguments[2], options: LIBXML_NONET);
    if ($expected === false || $actual === false) {
        throw new RuntimeException('Missing or invalid test report');
    }
    $expected->registerXPathNamespace('p', 'https://xml.phpunit.de/testSuite');
    $ids = [];
    foreach ($expected->xpath('//p:testMethod') as $method) {
        $id = (string) $method['id'];
        if (isset($ids[$id])) {
            throw new RuntimeException('Test selected twice: ' . $id);
        }
        $ids[$id] = true;
    }
    if ($ids === []) {
        throw new RuntimeException('No tests selected');
    }
    $seen = [];
    $skipped = [];
    foreach ($actual->xpath('//testcase') as $case) {
        $class = (string) $case['class'];
        if ($class === '') {
            $class = str_replace('.', '\\', (string) $case['classname']);
        }
        $name = (string) $case['name'];
        if (preg_match('/^(\w+) with data set (?:"(.*)"|#(\d+))$/s', $name, $matches)) {
            $name = $matches[1] . '#' . ($matches[3] ?? $matches[2]);
        }
        $id = $class . '::' . $name;
        if (!isset($ids[$id]) || isset($seen[$id])) {
            throw new RuntimeException('Unexpected or duplicate test result: ' . $id);
        }
        $seen[$id] = true;
        if (isset($case->failure) || isset($case->error)) {
            throw new RuntimeException('Failed test: ' . $id);
        }
        if (isset($case->skipped)) {
            $skipped[] = $id;
        }
    }
    $missing = array_diff_key($ids, $seen);
    if ($missing !== []) {
        throw new RuntimeException('Tests did not report an outcome: ' . implode(', ', array_keys($missing)));
    }
    // Existing engine/credential skips remain visible while suites migrate to strict group selection.
    // Skips are never reported as executed assertions, even on a non-strict legacy lane.
    foreach ($skipped as $id) {
        fwrite(STDERR, 'Skipped (not covered): ' . $id . PHP_EOL);
    }
    if ($skipped !== [] && in_array('--fail-on-skipped', $arguments, true)) {
        throw new RuntimeException('Unexpected skipped tests: ' . count($skipped));
    }
    echo count($seen) - count($skipped), ' completed, ', count($skipped), ' skipped', PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
