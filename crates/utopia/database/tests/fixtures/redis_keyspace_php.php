<?php

// Writes a fixed sequence of Redis adapter operations into namespace
// $argv[1] (DB 7 of compat-database-redis) and prints the keyspace as JSON,
// the namespace written as {ns}.

require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\Redis as RedisAdapter;
use Utopia\Database\Attribute;
use Utopia\Database\Change;
use Utopia\Database\Document;
use Utopia\Database\Index;
use Utopia\Database\Operator;
use Utopia\Database\Permission;
use Utopia\Database\Relationship;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\OrderDirection;

$ns = $argv[1] ?? 'oracle_php';
$mode = $argv[2] ?? 'write';
$redis = new Redis();
$redis->connect('compat-database-redis', 6379);
$redis->select(7);

function dump(Redis $redis, string $ns): void
{
    $keys = $redis->keys('utopia:'.$ns.'*');
    sort($keys);
    $out = [];
    foreach ($keys as $key) {
        $type = $redis->type($key);
        $name = str_replace($ns, '{ns}', $key);
        switch ($type) {
            case Redis::REDIS_STRING:
                $out[$name] = ['string', str_replace($ns, '{ns}', $redis->get($key))];
                break;
            case Redis::REDIS_SET:
                $members = array_map(fn ($m) => str_replace($ns, '{ns}', (string) $m), $redis->sMembers($key));
                sort($members);
                $out[$name] = ['set', $members];
                break;
            case Redis::REDIS_HASH:
                $pairs = [];
                foreach ($redis->hGetAll($key) as $f => $v) {
                    $pairs[] = [(string) $f, str_replace($ns, '{ns}', (string) $v)];
                }
                $out[$name] = ['hash', $pairs];
                break;
        }
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

if ($mode === 'dump') {
    dump($redis, $ns);
    exit;
}

if ($mode === 'read') {
    $a = new RedisAdapter($redis);
    $auth = new Authorization();
    $auth->disable();
    $a->setAuthorization($auth);
    $a->setDatabase('db');
    $a->setNamespace($ns);
    $notes = new Document(['$id' => 'notes']);
    $docs = $a->find($notes, [], 25, null, ['count'], [OrderDirection::Asc]);
    echo json_encode(array_map(fn ($d) => $d->getArrayCopy(), $docs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
    echo json_encode($a->getDocument($notes, 'doc1')->getArrayCopy(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
    echo $a->count($notes), ' ', $a->sum($notes, 'count'), "\n";
    exit;
}

foreach ($redis->keys('utopia:'.$ns.'*') as $key) {
    $redis->del($key);
}

$a = new RedisAdapter($redis);
$auth = new Authorization();
$auth->disable();
$a->setAuthorization($auth);
$a->setDatabase('db');
$a->setNamespace($ns);
$a->create('db');
$a->createCollection('notes', [
    Attribute::string(key: 'title', size: 64),
    Attribute::integer(key: 'count'),
    Attribute::bigInteger(key: 'big'),
    Attribute::string(key: 'tags', size: 16, array: true),
    Attribute::object(key: 'meta'),
    Attribute::float(key: 'ratio'),
], [
    Index::unique(key: 'u_title', attributes: ['title'], lengths: [64]),
    Index::key(key: 'k_count', attributes: ['count'], orders: [OrderDirection::Desc]),
]);
$a->createCollection('other', [], []);
$a->createAttribute('notes', Attribute::string(key: 'extra', size: 10));
$a->updateAttribute('notes', 'extra', Attribute::string(key: 'renamed', size: 20, required: true));
$a->createIndex('notes', Index::key(key: 'k_big', attributes: ['big']));
$a->renameIndex('notes', 'k_big', 'k_big2');
$notes = new Document(['$id' => 'notes']);
$a->createDocument($notes, new Document([
    '$id' => 'Doc1',
    '$permissions' => [
        Permission::read(Role::any()),
        Permission::update(Role::user('a')),
        Permission::delete(Role::user('a')),
        Permission::read(Role::user('a')),
    ],
    '$createdAt' => '2026-01-01 00:00:00.000',
    '$updatedAt' => '2026-01-01 00:00:00.000',
    'title' => 'héllo/ "world" ✓ <tag> & \'q\'',
    'count' => 5,
    'big' => '18446744073709551615',
    'tags' => ['a', 'b'],
    'meta' => ['x' => [1, 2], 'y' => [], 'z' => 1.0, 'w' => ['$id' => 'nested', 'v' => 0.5]],
    'ratio' => 1.0,
]));
$a->createDocument($notes, new Document(['$id' => 'doc2', '$permissions' => [], 'title' => 'two', 'count' => 7, 'ratio' => 2.5, 'tags' => []]));
$a->updateDocument($notes, 'doc2', new Document([
    'count' => Operator::increment(3),
    'tags' => Operator::arrayAppend(['z']),
    'title' => Operator::stringConcat('!'),
    'ratio' => Operator::multiply(2),
    '$permissions' => [Permission::read(Role::users())],
]), false);
$a->increaseDocumentAttribute($notes, 'Doc1', 'count', 2, '2026-01-02 00:00:00.000');
$a->upsertDocuments($notes, [
    new Change(new Document(), new Document(['$id' => 'doc3', '$permissions' => [Permission::read(Role::any())], 'title' => 'three', 'count' => 1])),
    new Change(new Document(), new Document(['$id' => 'doc2', '$permissions' => [Permission::read(Role::any())], 'title' => 'two!!', 'count' => 1])),
], 'count');
$a->createRelationship('notes', Relationship::oneToOne(relatedCollection: 'other', twoWay: true, key: 'partner', twoWayKey: 'partnerOf'));
$a->deleteAttribute('notes', 'ratio');
$a->renameAttribute('notes', 'tags', 'labels');
$a->deleteDocument($notes, 'doc3');
$a->startTransaction();
$a->createDocument($notes, new Document(['$id' => 'doc4', '$permissions' => [Permission::read(Role::any())], 'title' => 'four']));
$a->updateDocument($notes, 'Doc1', new Document(['title' => 'changed', '$permissions' => []]), false);
$a->deleteDocument($notes, 'doc2');
$a->rollbackTransaction();
$a->startTransaction();
$a->createDocument($notes, new Document(['$id' => 'doc5', '$sequence' => '40', '$permissions' => [Permission::write(Role::any())], 'title' => 'five']));
$a->commitTransaction();

$s = new RedisAdapter($redis);
$s->setAuthorization($auth);
$s->setDatabase('db');
$s->setNamespace($ns.'_shared');
$s->setSharedTables(true);
$s->setTenant(3);
$s->create('db');
$s->createCollection('notes', [Attribute::string(key: 'title', size: 64)], [Index::unique(key: 'u', attributes: ['title'])]);
$s->createDocument($notes, new Document(['$id' => 'a', '$tenant' => 3, '$permissions' => [Permission::read(Role::any())], 'title' => 'x']));
$s->createDocument($notes, new Document(['$id' => 'b', '$tenant' => 4, '$permissions' => [Permission::read(Role::team('t', 'owner'))], 'title' => 'x']));
$s->setTenant(null);
$s->createDocument(new Document(['$id' => '_metadata']), new Document(['$id' => 'notes', '$permissions' => [], 'name' => 'notes']));

dump($redis, $ns);
