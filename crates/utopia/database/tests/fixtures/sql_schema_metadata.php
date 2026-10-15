<?php
// The schema paths that read a collection's stored definition (getDocument() on the metadata collection):
// MariaDB/MySQL createIndex(), SQLite deleteAttribute()/renameIndex()/fulltext deleteIndex(), Postgres shared
// renameIndex(), and many-to-many updateRelationship()/deleteRelationship() (junction names).
require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Event;
use Utopia\Database\Index;
use Utopia\Database\Relationship;
use Utopia\Database\Relationship\Update;
use Utopia\Database\RelationshipSide;
use Utopia\Query\OrderDirection;

$hook = new class () implements \Utopia\Database\Hook\Transform {
    public array $log = [];
    public bool $recording = false;
    public function transform(Event $event, string $query): string
    {
        if ($this->recording) {
            $this->log[] = [$event->value, $query];
        }
        return $query;
    }
};

$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_STRINGIFY_FETCHES => true];

$out = [];
foreach (['sqlite', 'postgres', 'mariadb', 'mysql'] as $engine) {
    foreach ([false, true] as $shared) {
        $name = 'schema_meta'.($shared ? '_shared' : '');
        $pdo = match ($engine) {
            'sqlite' => new PDO('sqlite::memory:'),
            'postgres' => new PDO('pgsql:host=compat-database-postgres;port=5432', 'root', 'password', $opts),
            'mariadb' => new PDO('mysql:host=compat-database-mariadb;port=3306;charset=utf8mb4', 'root', 'password', $opts),
            'mysql' => new PDO('mysql:host=compat-database-mysql;port=3306;charset=utf8mb4', 'root', 'password', $opts),
        };
        $a = match ($engine) {
            'sqlite' => new SQLite($pdo),
            'postgres' => new Postgres($pdo),
            'mariadb' => new MariaDB($pdo),
            'mysql' => new MySQL($pdo),
        };
        $a->setDatabase($name);
        $a->setNamespace('ns');
        $a->setSharedTables($shared);
        if ($shared) {
            $a->setTenant(7);
        }
        $a->addTransform('rec', $hook);
        try { $a->delete($name); } catch (Throwable) {}
        $a->create($name);

        $movieAttributes = [
            Attribute::string('title', 128),
            Attribute::string('a', 32),
            Attribute::string('b', 32),
            Attribute::text('notes'),
            Attribute::string('tags', 64, array: true),
        ];
        $movieIndexes = [
            Index::key('idx_ab', ['a', 'b'], [], [OrderDirection::Asc, OrderDirection::Desc]),
            Index::fulltext('ft_notes', ['notes']),
        ];
        $a->createCollection('_metadata', [Attribute::string('name', 256), Attribute::longText('attributes'), Attribute::longText('indexes')], []);
        $a->createCollection('movies', $movieAttributes, $movieIndexes);
        $a->createCollection('people', [Attribute::string('name', 64)], []);
        $a->createCollection('_1_2', [Attribute::string('fans', 255), Attribute::string('liked', 255)], []);

        $q = fn (string $id) => $engine === 'postgres' ? '"'.$id.'"' : '`'.$id.'`';
        $table = $engine === 'sqlite' ? $q('ns__metadata') : $q($name).'.'.$q('ns__metadata');
        $json = fn (array $items) => json_encode(array_map(fn ($i) => $i->toDocument()->getArrayCopy(), $items));
        $seed = [];
        foreach ([[1, 'movies', $movieAttributes, $movieIndexes], [2, 'people', [Attribute::string('name', 64)], []]] as [$id, $uid, $attrs, $idx]) {
            $columns = [$q('_id'), $q('_uid'), $q('name'), $q('attributes'), $q('indexes')];
            $values = [(string) $id, $pdo->quote($uid), $pdo->quote($uid), $pdo->quote($json($attrs)), $pdo->quote($json($idx))];
            if ($shared) {
                $columns[] = $q('_tenant');
                $values[] = '7';
            }
            $seed[] = 'INSERT INTO '.$table.' ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).')';
        }
        foreach ($seed as $sql) {
            $pdo->exec($sql);
        }

        $steps = [];
        $step = function (string $label, callable $fn) use (&$steps, $hook) {
            $hook->log = [];
            $hook->recording = true;
            try {
                $result = $fn();
                $error = null;
            } catch (Throwable $e) {
                $result = null;
                $error = get_class($e).': '.$e->getMessage();
            }
            $hook->recording = false;
            $steps[] = ['step' => $label, 'statements' => $hook->log, 'result' => is_bool($result) ? $result : null, 'error' => $error];
        };
        if ($engine === 'mariadb' || $engine === 'mysql') {
            $step('createIndex', fn () => $a->createIndex('movies', Index::key('idx_title', ['title'], [16], [OrderDirection::Desc])));
            if ($engine === 'mysql') {
                $step('createIndex array', fn () => $a->createIndex('movies', Index::key('idx_tags', ['tags'])));
            }
            $step('createIndex fulltext', fn () => $a->createIndex('movies', Index::fulltext('ft_title', ['title'])));
            $step('createIndex missing collection', fn () => $a->createIndex('nope', Index::key('idx_x', ['x'])));
        }
        if ($engine === 'postgres') {
            $step('renameIndex', fn () => $a->renameIndex('movies', 'idx_ab', 'idx_ab2'));
            $step('renameIndex missing', fn () => $a->renameIndex('movies', 'nope', 'nope2'));
        }
        if ($engine === 'sqlite') {
            $step('renameIndex', fn () => $a->renameIndex('movies', 'idx_ab', 'idx_ab2'));
            $step('renameIndex missing', fn () => $a->renameIndex('movies', 'nope', 'nope2'));
            $step('deleteAttribute', fn () => $a->deleteAttribute('movies', 'b'));
            $step('deleteIndex fulltext', fn () => $a->deleteIndex('movies', 'ft_notes'));
            $step('deleteAttribute missing collection', fn () => $a->deleteAttribute('nope', 'b'));
        }
        $step('updateRelationship manyToMany', fn () => $a->updateRelationship('movies', Relationship::manyToMany('people', key: 'fans', twoWayKey: 'liked'), RelationshipSide::Parent, new Update(key: 'fans2', twoWayKey: 'liked2')));
        $step('deleteRelationship manyToMany', fn () => $a->deleteRelationship('movies', Relationship::manyToMany('people', key: 'fans2', twoWayKey: 'liked2'), RelationshipSide::Parent));
        $out[] = ['engine' => $engine, 'shared' => $shared, 'seed' => $seed, 'steps' => $steps];
        try { $a->delete($name); } catch (Throwable) {}
    }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
