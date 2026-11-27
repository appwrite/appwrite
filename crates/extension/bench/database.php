<?php

// Times utopia-php/database's Id, Role, Permission and DateTime: `python3 crates/extension/bench/run.py crates/extension/bench/database.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Database\DateTime;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\PermissionType;
use Utopia\Database\Role;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(Role::class))->isInternal();
$libraries = array_filter(array_map(trim(...), explode(',', (string) ini_get('utopia.libraries'))), fn (string $name): bool => $name !== '');
$requested = $libraries === [] || in_array('database', $libraries, true);
if ($internal !== $requested) {
    fwrite(STDERR, 'utopia.libraries ' . ($requested ? 'is unset or names' : 'does not name') . ' database, but the other implementation loaded' . PHP_EOL);
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

date_default_timezone_set('UTC');

$userId = '6564a1b2c3d4e5f6a7b8';
$role = Role::user($userId);
$team = Role::team('team1', 'owner');
$documentPermissions = [
    'read("any")',
    'read("user:' . $userId . '")',
    'write("user:' . $userId . '")',
    'update("team:team1/owner")',
    'delete("label:vip")',
];
$date = new \DateTime('2024-01-02 03:04:05.678');
$stored = '2024-01-02 03:04:05.678';

$results = [
    'Id::unique' => measure(20000, fn () => Id::unique()),
    'Id::unique(0)' => measure(20000, fn () => Id::unique(0)),
    'Id::custom' => measure(500000, fn () => Id::custom('custom-id')),
    'Role::any' => measure(300000, fn () => Role::any()),
    'Role::user' => measure(300000, fn () => Role::user($userId)),
    'Role::team(id, dim)' => measure(300000, fn () => Role::team('team1', 'owner')),
    'Role->toString' => measure(300000, fn () => $team->toString()),
    'Role->getIdentifier' => measure(500000, fn () => $role->getIdentifier()),
    'Role::parse' => measure(200000, fn () => Role::parse('team:team1/owner')),
    'read(Role::any())' => measure(300000, fn () => Permission::read(Role::any())),
    'read(Role::user($id))' => measure(300000, fn () => Permission::read(Role::user($userId))),
    'update(team role)' => measure(300000, fn () => Permission::update($team)),
    'Permission::parse' => measure(200000, fn () => Permission::parse('read("user:' . '6564a1b2c3d4e5f6a7b8' . '")')),
    'Permission::parse dimension' => measure(200000, fn () => Permission::parse('update("team:team1/owner")')),
    'Permission->toString' => measure(300000, fn () => (new Permission('read', 'user', $userId))->toString()),
    'aggregate(5)' => measure(50000, fn () => Permission::aggregate($documentPermissions)),
    'aggregate(5, allowed)' => measure(50000, fn () => Permission::aggregate($documentPermissions, [PermissionType::Read, PermissionType::Update])),
    'aggregate(null)' => measure(300000, fn () => Permission::aggregate(null)),
    'DateTime::now' => measure(200000, fn () => DateTime::now()),
    'DateTime::format' => measure(300000, fn () => DateTime::format($date)),
    'DateTime::addSeconds' => measure(100000, fn () => DateTime::addSeconds(new \DateTime(), 3600)),
    'DateTime::formatTz' => measure(200000, fn () => DateTime::formatTz($stored)),
    'DateTime::setTimezone' => measure(100000, fn () => DateTime::setTimezone('2024-01-02T03:04:05.678+02:00')),
    'DateTime::nowAfter' => measure(100000, fn () => DateTime::nowAfter($stored)),
];
$results['peak memory MB'] = round(memory_get_peak_usage() / 1048576, 1);

echo json_encode($results), PHP_EOL;
