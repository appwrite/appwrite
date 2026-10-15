<?php
// Records every statement (query, bindings, event) the SQL adapters run for a fixed scenario,
// per engine and table-sharing mode. Usage: php oracle.php <engine> <shared 0|1>
require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Change;
use Utopia\Database\Collection;
use Utopia\Database\Document;
use Utopia\Database\Operator;
use Utopia\Database\PDO;
use Utopia\Database\Profiler;
use Utopia\Database\Query;

$engine = $argv[1];
$shared = $argv[2] === '1';
$attrs = [\PDO::ATTR_TIMEOUT => 3, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => true, \PDO::ATTR_STRINGIFY_FETCHES => true];
$db = 'bdocs_'.$engine.($shared ? '_shared' : '');
switch ($engine) {
    case 'mariadb': $adapter = new MariaDB(new PDO('mysql:host=compat-database-mariadb;port=3306;charset=utf8mb4', 'root', 'password', $attrs)); break;
    case 'mysql': $adapter = new MySQL(new PDO('mysql:host=compat-database-mysql;port=3306;charset=utf8mb4', 'root', 'password', $attrs)); break;
    case 'postgres': $adapter = new Postgres(new PDO('pgsql:host=compat-database-postgres;port=5432', 'root', 'password', $attrs)); break;
    case 'sqlite': $adapter = new SQLite(new PDO('sqlite::memory:', null, null, $attrs)); break;
}
$adapter->setDatabase($db);
$adapter->setNamespace('ns');
$adapter->setSharedTables($shared);
if ($shared) {
    $adapter->setTenant(7);
}
try { $adapter->delete($db); } catch (\Throwable $e) {}
if ($engine !== 'sqlite') { $adapter->create($db); }

$spatial = $engine !== 'sqlite';
$attributes = [
    Attribute::string('title', 128),
    Attribute::integer('count'),
    Attribute::double('price'),
    Attribute::boolean('active'),
    Attribute::string('tags', 64, array: true),
    Attribute::string('note', 256),
    Attribute::datetime('date'),
];
if ($spatial) { $attributes[] = Attribute::point('loc'); }
$collection = Collection::create('docs', attributes: $attributes);

$profiler = (new Profiler())->enable();
$adapter->setProfiler($profiler);
$out = [];
$seen = 0;
$step = function (string $name, callable $fn) use (&$out, &$seen, $profiler) {
    try { $result = $fn(); $error = null; } catch (\Throwable $e) { $result = null; $error = get_class($e).': '.$e->getMessage(); }
    $logs = $profiler->getLogs();
    $norm = function ($v) use (&$norm) {
        if ($v instanceof Document) { return $norm($v->getArrayCopy()); }
        if (is_array($v)) { return array_map($norm, $v); }
        if (is_string($v) && ! mb_check_encoding($v, 'UTF-8')) { return 'hex:'.bin2hex($v); }
        return $v;
    };
    $stmts = [];
    foreach (array_slice($logs, $seen) as $log) {
        $stmts[] = ['op' => $log->operation, 'q' => $log->query, 'b' => $norm($log->bindings)];
    }
    $seen = count($logs);
    $out[] = ['step' => $name, 'statements' => $stmts, 'result' => $norm($result), 'error' => $error];
};

$step('createCollection', fn () => $adapter->createCollection('docs', $attributes, []));
$doc1 = new Document(['$id' => 'doc1', '$permissions' => ['read("any")', 'update("user:a/b")'], '$createdAt' => '2024-01-02 03:04:05.678', '$updatedAt' => '2024-01-02 03:04:05.678',
    'title' => 'Hello "world"', 'count' => 5, 'price' => 1.5, 'active' => true, 'tags' => ['a', 'b/c'], 'note' => 'x', 'date' => '2024-01-01 00:00:00.000']);
if ($spatial) { $doc1['loc'] = [1.5, 2]; }
$step('createDocument', fn () => $adapter->createDocument($collection, $doc1));
$mk = fn ($id, $extra = []) => new Document(array_merge(['$id' => $id, '$permissions' => ['read("any")'], '$createdAt' => '2024-01-02 03:04:05.678', '$updatedAt' => '2024-01-02 03:04:05.678', 'title' => $id, 'count' => 1, 'price' => 0.25, 'active' => false, 'tags' => [], 'note' => null], $extra));
$step('createDocuments', fn () => $adapter->createDocuments($collection, [$mk('doc2'), $mk('doc3', ['count' => 2])]));
$step('createDocumentsIgnore', fn () => $adapter->ignoreDuplicates(fn () => $adapter->createDocuments($collection, [$mk('doc2', ['count' => 99]), $mk('doc4'), $mk('doc4', ['count' => 98])])));
$step('getDocument', fn () => $adapter->getDocument($collection, 'doc1'));
$step('getDocumentMissing', fn () => $adapter->getDocument($collection, 'nope'));
$step('getDocumentSelect', fn () => $adapter->getDocument($collection, 'doc1', [Query::select(['title', 'count', '$id'])]));
$step('getDocumentForUpdate', fn () => $adapter->getDocument($collection, 'doc2', [], true));
$step('getDocumentSelectForUpdate', fn () => $adapter->getDocument($collection, 'doc2', [Query::select(['title'])], true));
$step('getSequences', fn () => $adapter->getSequences($collection, [new Document(['$id' => 'doc1']), new Document(['$id' => 'doc3', '$sequence' => '3']), new Document(['$id' => 'doc4'])]));
$cur = $adapter->getDocument($collection, 'doc1');
$seen = count($profiler->getLogs());
$upd = new Document(array_merge($cur->getArrayCopy(), ['title' => 'Changed', 'active' => false, 'tags' => ['z'], '$updatedAt' => '2024-02-02 00:00:00.000']));
if ($spatial) { $upd['loc'] = [3, 4.25]; }
$step('updateDocument', fn () => $adapter->updateDocument($collection, 'doc1', $upd, false));
$renamed = new Document(array_merge($cur->getArrayCopy(), ['$id' => 'doc1b', 'title' => 'Renamed']));
if ($spatial) { $renamed['loc'] = [3, 4.25]; }
$step('updateDocumentRename', fn () => $adapter->updateDocument($collection, 'doc1', $renamed, true));
$ops = [
    'increment' => ['count', Operator::increment(2)],
    'incrementMax' => ['count', Operator::increment(2, 100)],
    'decrement' => ['count', Operator::decrement(1)],
    'decrementMin' => ['count', Operator::decrement(1, 0)],
    'multiply' => ['price', Operator::multiply(2)],
    'multiplyMax' => ['price', Operator::multiply(2, 1000.5)],
    'divide' => ['price', Operator::divide(2)],
    'divideMin' => ['price', Operator::divide(2, 0.1)],
    'modulo' => ['count', Operator::modulo(3)],
    'power' => ['count', Operator::power(2)],
    'powerMax' => ['count', Operator::power(2, 1000)],
    'powerOddMax' => ['count', Operator::power(3, 1000)],
    'stringConcat' => ['title', Operator::stringConcat('!')],
    'stringReplace' => ['title', Operator::stringReplace('R', 'r')],
    'toggle' => ['active', Operator::toggle()],
    'arrayAppend' => ['tags', Operator::arrayAppend(['x', 'y'])],
    'arrayPrepend' => ['tags', Operator::arrayPrepend(['w'])],
    'arrayInsert' => ['tags', Operator::arrayInsert(1, 'i')],
    'arrayRemove' => ['tags', Operator::arrayRemove('x')],
    'arrayUnique' => ['tags', Operator::arrayUnique()],
    'arrayIntersect' => ['tags', Operator::arrayIntersect(['w', 'i', 'y'])],
    'arrayDiff' => ['tags', Operator::arrayDiff(['w'])],
    'arrayFilter' => ['tags', Operator::arrayFilter('notEqual', 'q')],
    'arrayFilterNull' => ['tags', Operator::arrayFilter('isNotNull')],
    'dateAddDays' => ['date', Operator::dateAddDays(3)],
    'dateSubDays' => ['date', Operator::dateSubDays(1)],
];
foreach ($ops as $label => [$attribute, $operator]) {
    $current = $adapter->getDocument($collection, 'doc1b');
    $seen = count($profiler->getLogs());
    $d = new Document(array_merge($current->getArrayCopy(), [$attribute => $operator]));
    if ($spatial) { $d['loc'] = [3, 4.25]; }
    $step('op_'.$label, fn () => $adapter->updateDocument($collection, 'doc1b', $d, true));
}
$step('afterOps', fn () => $adapter->getDocument($collection, 'doc1b'));
$seqs = array_map(fn ($d) => $d->getSequence(), $adapter->getSequences($collection, [new Document(['$id' => 'doc2']), new Document(['$id' => 'doc3'])]));
$seen = count($profiler->getLogs());
$step('updateDocuments', fn () => $adapter->updateDocuments($collection, new Document(['note' => 'bulk', 'active' => true, 'tags' => ['m'], '$updatedAt' => '2024-03-03 00:00:00.000', '$permissions' => ['read("any")', 'delete("any")']]),
    [new Document(['$id' => 'doc2', '$sequence' => $seqs[0]]), new Document(['$id' => 'doc3', '$sequence' => $seqs[1]])]));
$step('updateDocumentsOperator', fn () => $adapter->updateDocuments($collection, new Document(['count' => Operator::increment(10, 50), '$updatedAt' => '2024-03-04 00:00:00.000']),
    [new Document(['$id' => 'doc2', '$sequence' => $seqs[0]]), new Document(['$id' => 'doc3', '$sequence' => $seqs[1]])]));
$step('increase', fn () => $adapter->increaseDocumentAttribute($collection, 'doc2', 'count', 3, '2024-04-04 00:00:00.000'));
$step('increaseBounded', fn () => $adapter->increaseDocumentAttribute($collection, 'doc2', 'count', -1, '2024-04-05 00:00:00.000', 0, 100));
$step('increaseNegBounds', fn () => $adapter->increaseDocumentAttribute($collection, 'doc2', 'price', 1.5, '2024-04-05 00:00:00.000', 1, -5));
$up = fn ($id, $extra = []) => new Document(array_merge(['$id' => $id, '$permissions' => ['read("any")'], '$createdAt' => '2024-05-05 05:05:05.000', '$updatedAt' => '2024-05-05 05:05:05.000', 'title' => $id, 'count' => 4, 'price' => 2.5, 'active' => true, 'tags' => ['u'], 'note' => 'n'], $extra));
$step('upsert', fn () => $adapter->upsertDocuments($collection, [new Change(new Document(), $up('doc5')), new Change(new Document(['$id' => 'doc2']), $up('doc2', ['title' => 'up2']))]));
$step('upsertIncrease', fn () => $adapter->upsertDocuments($collection, [new Change(new Document(['$id' => 'doc5']), $up('doc5', ['count' => 6]))], 'count'));
$step('upsertOperators', fn () => $adapter->upsertDocuments($collection, [
    new Change(new Document(['$id' => 'doc5']), $up('doc5', ['count' => Operator::increment(5), 'tags' => Operator::arrayAppend(['v'])])),
    new Change(new Document(), $up('doc6', ['count' => Operator::increment(5), 'tags' => Operator::arrayAppend(['v'])])),
    new Change(new Document(['$id' => 'doc2']), $up('doc2', ['price' => Operator::multiply(3)])),
    new Change(new Document(), $up('doc7')),
]));
$step('upsertOne', fn () => $adapter->upsertDocument($collection, new Change(new Document(), $up('doc8', ['note' => Operator::stringConcat('!')]))));
$step('afterUpserts', fn () => [$adapter->getDocument($collection, 'doc5'), $adapter->getDocument($collection, 'doc6'), $adapter->getDocument($collection, 'doc8')]);
$step('deleteDocument', fn () => $adapter->deleteDocument($collection, 'doc4'));
$step('deleteDocumentMissing', fn () => $adapter->deleteDocument($collection, 'doc4'));
$seqs = array_map(fn ($d) => $d->getSequence(), $adapter->getSequences($collection, [new Document(['$id' => 'doc5']), new Document(['$id' => 'doc6'])]));
$seen = count($profiler->getLogs());
$step('deleteDocuments', fn () => $adapter->deleteDocuments($collection, $seqs, ['doc5', 'doc6']));
$step('rawQuery', fn () => $adapter->rawQuery('SELECT 1 AS one, ? AS two', ['x']));
$step('rawMutation', fn () => $adapter->rawMutation('DELETE FROM '.($engine === 'postgres' ? '"'.$db.'"."ns_docs"' : ($engine === 'sqlite' ? '`ns_docs`' : '`'.$db.'`.`ns_docs`')).' WHERE _uid = ?', ['doc7']));

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
