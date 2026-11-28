<?php

// Times utopia-php/database's Key, Label and UID validators, and the package's Roles and Permissions,
// which build them for every role they check: `python3 crates/extension/bench/run.py
// crates/extension/bench/database-validators.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Label;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(Key::class))->isInternal();
$libraries = array_filter(array_map(trim(...), explode(',', (string) ini_get('utopia.libraries'))), fn (string $name): bool => $name !== '');
$switched = array_map(trim(...), explode(',', (string) getenv('_APP_EXTENSION_LIBRARIES')));
$requested = $libraries === [] ? in_array('database-validators', $switched, true) : in_array('database-validators', $libraries, true);
if ($internal !== $requested) {
    fwrite(STDERR, 'database-validators is ' . ($requested ? '' : 'not ') . 'switched on, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

/** Nanoseconds per call of $run, the best of 7 batches of $n. */
function measure(int $n, callable $run): float
{
    $run();
    $best = INF;
    for ($batch = 0; $batch < 7; $batch++) {
        $start = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $run();
        }
        $best = min($best, (hrtime(true) - $start) / $n);
    }

    return $best;
}

/** Appwrite's CustomId. */
class CustomId extends Key
{
    public function isValid($value): bool
    {
        return $value == 'unique()' || parent::isValid($value);
    }
}

$key = new Key();
$internalKey = new Key(true);
$label = new Label();
$uid = new UID();
$customId = new CustomId();
$id = '6564a1b2c3d4e5f6a7b8';
$long = str_repeat('x', 37);
$permissions = new Permissions();
$documentPermissions = ['read("any")', 'read("user:' . $id . '")', 'update("team:team1/owner")', 'delete("label:vip")', 'write("user:' . $id . '")'];

$results = [
    'new Key()' => measure(500000, fn () => new Key()),
    'new Key(maxLength: 81)' => measure(500000, fn () => new Key(maxLength: 81)),
    'new Label()' => measure(500000, fn () => new Label()),
    'new UID()' => measure(500000, fn () => new UID()),
    'Key->isValid(id)' => measure(1000000, fn () => $key->isValid($id)),
    'Key->isValid(36 chars)' => measure(1000000, fn () => $key->isValid('6564a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6')),
    'Key->isValid(too long)' => measure(1000000, fn () => $key->isValid($long)),
    'Key->isValid(bad char)' => measure(1000000, fn () => $key->isValid('abc def')),
    'Key->isValid($id, internal)' => measure(1000000, fn () => $internalKey->isValid('$createdAt')),
    'Key->isValid(int)' => measure(1000000, fn () => $key->isValid(5)),
    'Label->isValid' => measure(1000000, fn () => $label->isValid('vip')),
    'UID->isValid' => measure(1000000, fn () => $uid->isValid($id)),
    'CustomId->isValid' => measure(1000000, fn () => $customId->isValid($id)),
    'Key->getDescription' => measure(1000000, fn () => $key->getDescription()),
    'UID->getDescription' => measure(1000000, fn () => $uid->getDescription()),
    'new UID()->isValid' => measure(500000, fn () => (new UID())->isValid($id)),
    'Permissions->isValid(5)' => measure(100000, fn () => $permissions->isValid($documentPermissions)),
];
$results['peak memory MB'] = round(memory_get_peak_usage() / 1048576, 1);

echo json_encode($results), PHP_EOL;
