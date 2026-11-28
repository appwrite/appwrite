<?php

// Runs the ops of a JSON scenario on Utopia\Database\Adapter\Memory, printing one line per op:
// the op index and base64(serialize(result)), then the adapter's properties.
require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\Memory;
use Utopia\Database\Attribute;
use Utopia\Database\Document;
use Utopia\Database\Index;
use Utopia\Database\Operator;
use Utopia\Database\PermissionType;
use Utopia\Database\Query;
use Utopia\Database\Relationship;
use Utopia\Database\Relationship\Update;
use Utopia\Database\RelationshipSide;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\CursorDirection;
use Utopia\Query\OrderDirection;

$ops = json_decode(file_get_contents($argv[1]), true);
$adapter = new Memory();
$auth = new Authorization();
$adapter->setAuthorization($auth);

function values(mixed $v): mixed
{
    if (is_array($v)) {
        if (isset($v['$op'])) {
            return Operator::parse(json_encode($v['$op']));
        }
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = values($x);
        }
        return $out;
    }
    return $v;
}

function doc(array $d): Document
{
    return new Document(values($d));
}

function export(mixed $r): mixed
{
    if ($r instanceof Document) {
        return $r->getArrayCopy();
    }
    if (is_array($r)) {
        return array_map('export', $r);
    }
    return $r;
}

function coll(string $id): Document
{
    return new Document(['$id' => $id]);
}

foreach ($ops as $i => $op) {
    $name = $op[0];
    $a = \array_key_exists(1, $op) ? $op[1] : [];
    try {
        $r = match ($name) {
            'database' => $adapter->setDatabase($a) && null,
            'namespace' => $adapter->setNamespace($a) && null,
            'shared' => $adapter->setSharedTables($a) && null,
            'tenant' => $adapter->setTenant($a) && null,
            'perDocument' => $adapter->setTenantPerDocument($a) && null,
            'roles' => (function () use ($auth, $a) {
                $auth->cleanRoles();
                foreach ($a as $r) {
                    $auth->addRole($r);
                } return;
            })(),
            'authorization' => (function () use ($auth, $a) {
                $a ? $auth->enable() : $auth->disable();
                return;
            })(),
            'ignore' => $adapter->ignoreDuplicates(fn () => $adapter->createDocuments(coll($a[0]), array_map('doc', $a[1]))),
            'create' => $adapter->create($a),
            'update' => $adapter->update($a[0], $a[1]),
            'exists' => $adapter->exists($a),
            'collectionExists' => $adapter->collectionExists($a[0], $a[1]),
            'list' => $adapter->list(),
            'delete' => $adapter->delete($a),
            'createCollection' => $adapter->createCollection($a[0], array_map(fn ($x) => Attribute::fromDocument(new Document($x)), $a[1] ?? []), array_map(fn ($x) => Index::fromDocument(new Document($x)), $a[2] ?? [])),
            'deleteCollection' => $adapter->deleteCollection($a),
            'createAttribute' => $adapter->createAttribute($a[0], Attribute::fromDocument(new Document($a[1]))),
            'updateAttribute' => $adapter->updateAttribute($a[0], $a[1], Attribute::fromDocument(new Document($a[2]))),
            'deleteAttribute' => $adapter->deleteAttribute($a[0], $a[1]),
            'renameAttribute' => $adapter->renameAttribute($a[0], $a[1], $a[2]),
            'createIndex' => $adapter->createIndex($a[0], Index::fromDocument(new Document($a[1]))),
            'deleteIndex' => $adapter->deleteIndex($a[0], $a[1]),
            'renameIndex' => $adapter->renameIndex($a[0], $a[1], $a[2]),
            'createRelationship' => $adapter->createRelationship($a[0], Relationship::fromDocument(new Document($a[1]))),
            'updateRelationship' => $adapter->updateRelationship($a[0], Relationship::fromDocument(new Document($a[1])), RelationshipSide::from($a[2]), new Update(key: $a[3]['key'] ?? null, twoWayKey: $a[3]['twoWayKey'] ?? null, twoWay: $a[3]['twoWay'] ?? null)),
            'deleteRelationship' => $adapter->deleteRelationship($a[0], Relationship::fromDocument(new Document($a[1])), RelationshipSide::from($a[2])),
            'createDocument' => $adapter->createDocument(coll($a[0]), doc($a[1])),
            'createDocuments' => $adapter->createDocuments(coll($a[0]), array_map('doc', $a[1])),
            'getDocument' => $adapter->getDocument(coll($a[0]), $a[1], array_map(fn ($q) => Query::parse(json_encode($q)), $a[2] ?? [])),
            'updateDocument' => $adapter->updateDocument(coll($a[0]), $a[1], doc($a[2]), $a[3] ?? false),
            'updateDocuments' => $adapter->updateDocuments(coll($a[0]), doc($a[1]), array_map('doc', $a[2])),
            'getSequences' => $adapter->getSequences(coll($a[0]), array_map('doc', $a[1])),
            'deleteDocument' => $adapter->deleteDocument(coll($a[0]), $a[1]),
            'deleteDocuments' => $adapter->deleteDocuments(coll($a[0]), $a[1], $a[2]),
            'increase' => $adapter->increaseDocumentAttribute(coll($a[0]), $a[1], $a[2], $a[3], $a[4], $a[5] ?? null, $a[6] ?? null),
            'find' => $adapter->find(
                coll($a[0]),
                array_map(fn ($q) => Query::parse(json_encode($q)), $a[1] ?? []),
                array_key_exists('limit', $a[2] ?? []) ? $a[2]['limit'] : 25,
                $a[2]['offset'] ?? null,
                $a[2]['orderAttributes'] ?? [],
                array_map(fn ($t) => OrderDirection::from($t), $a[2]['orderTypes'] ?? []),
                $a[2]['cursor'] ?? [],
                CursorDirection::from($a[2]['direction'] ?? 'after'),
                PermissionType::from($a[2]['permission'] ?? 'read'),
            ),
            'count' => $adapter->count(coll($a[0]), array_map(fn ($q) => Query::parse(json_encode($q)), $a[1] ?? []), $a[2] ?? null),
            'sum' => $adapter->sum(coll($a[0]), $a[1], array_map(fn ($q) => Query::parse(json_encode($q)), $a[2] ?? []), $a[3] ?? null),
            'size' => $adapter->getSizeOfCollection($a),
            'start' => $adapter->startTransaction(),
            'commit' => $adapter->commitTransaction(),
            'rollback' => $adapter->rollbackTransaction(),
            'apply' => (function () use ($adapter, $a) {
                $m = new ReflectionMethod($adapter, 'applyOperator');
                return $m->invoke($adapter, values($a[0]), Operator::parse(json_encode($a[1])));
            })(),
        };
    } catch (Throwable $e) {
        $r = ['error' => get_class($e), 'message' => $e->getMessage()];
    }
    echo $i, ' ', base64_encode(serialize(export($r))), "\n";
}

foreach (['databases', 'data', 'permissions', 'permissionsByDocument', 'permissionsByPermission', 'uniqueIndexHashes'] as $p) {
    $prop = new ReflectionProperty(Memory::class, $p);
    echo $p, ' ', base64_encode(serialize($prop->getValue($adapter))), "\n";
}
