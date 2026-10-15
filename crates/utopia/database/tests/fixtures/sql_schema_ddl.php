<?php
require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\MariaDB;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Event;
use Utopia\Database\Index;
use Utopia\Database\IntegerWidth;
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

function attributes(string $engine): array
{
    $list = [
        Attribute::string('title', 128, required: true),
        Attribute::string('blurb', 70000),
        Attribute::varchar('code', 32),
        Attribute::text('notes'),
        Attribute::integer('year'),
        Attribute::integer('big', signed: false, width: IntegerWidth::Bits64),
        Attribute::bigInteger('total', signed: false),
        Attribute::float('rating'),
        Attribute::double('score', signed: false),
        Attribute::boolean('active'),
        Attribute::datetime('released'),
        Attribute::string('tags', 64, array: true),
        Attribute::relationship('director', Relationship::manyToOne('people', key: 'director', twoWay: true, twoWayKey: 'movies'), RelationshipSide::Parent),
        Attribute::relationship('cast', Relationship::oneToMany('people', key: 'cast', twoWay: true, twoWayKey: 'movie'), RelationshipSide::Parent),
    ];
    if ($engine !== 'sqlite') {
        $list[] = Attribute::point('loc', required: true);
        $list[] = Attribute::lineString('route');
        $list[] = Attribute::polygon('area');
    }
    if ($engine === 'postgres') {
        $list[] = Attribute::object('meta');
        $list[] = Attribute::vector('embedding', 3);
    }
    return $list;
}

function indexes(string $engine): array
{
    $list = [
        Index::key('idx_title', ['title'], [], [OrderDirection::Desc]),
        Index::unique('uq_code', ['code']),
        Index::key('idx_multi', ['year', 'title'], [null, 32]),
        Index::key('idx_created', ['$createdAt']),
        Index::fulltext('ft_notes', ['notes']),
    ];
    if ($engine !== 'sqlite') {
        $list[] = Index::spatial('sp_loc', 'loc');
    }
    if ($engine === 'postgres') {
        $list[] = Index::trigram('tg_title', ['title']);
        $list[] = Index::object('obj_meta', 'meta');
        $list[] = Index::hnswCosine('hnsw_embedding', 'embedding');
    }
    if ($engine === 'mysql') {
        $list[] = Index::key('idx_tags', ['tags']);
    }
    return $list;
}

function adapter(string $engine): object
{
    return match ($engine) {
        'sqlite' => new SQLite(new PDO('sqlite::memory:')),
        'postgres' => new Postgres(new PDO('pgsql:host=compat-database-postgres;port=5432', 'root', 'password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_STRINGIFY_FETCHES => true])),
        'mariadb' => new MariaDB(new PDO('mysql:host=compat-database-mariadb;port=3306;charset=utf8mb4', 'root', 'password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_STRINGIFY_FETCHES => true])),
        'mysql' => new MySQL(new PDO('mysql:host=compat-database-mysql;port=3306;charset=utf8mb4', 'root', 'password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_STRINGIFY_FETCHES => true])),
    };
}

function schemaOf(object $a, string $c): array
{
    $cols = array_map(fn ($x) => [$x->name, $x->type, $x->length, $x->nullable], $a->getSchemaAttributes($c));
    $idx = array_map(fn ($x) => [$x->name, $x->type->value, $x->columns, $x->lengths], $a->getSchemaIndexes($c));
    return ['columns' => $cols, 'indexes' => $idx];
}

$out = [];
foreach (['sqlite', 'postgres', 'mariadb', 'mysql'] as $engine) {
    foreach ([false, true] as $shared) {
        $name = 'schema_ddl'.($shared ? '_shared' : '');
        $a = adapter($engine);
        $a->setDatabase($name);
        $a->setNamespace('ns');
        $a->setSharedTables($shared);
        if ($shared) {
            $a->setTenant(7);
        }
        $a->addTransform('rec', $hook);
        try { $a->delete($name); } catch (Throwable) {}
        $a->create($name);
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
            return $result;
        };
        $step('createCollection people', fn () => $a->createCollection('people', [Attribute::string('name', 64)], []));
        $step('createCollection movies', fn () => $a->createCollection('movies', attributes($engine), indexes($engine)));
        $schema = schemaOf($a, 'movies');
        $step('createAttribute', fn () => $a->createAttribute('movies', Attribute::string('extra', 64)));
        $step('createAttributes', fn () => $a->createAttributes('movies', [Attribute::integer('a1'), Attribute::boolean('a2', required: true)]));
        $step('updateAttribute', fn () => $a->updateAttribute('movies', 'extra', Attribute::string('extra2', 128)));
        $step('updateAttribute same', fn () => $a->updateAttribute('movies', 'a1', Attribute::bigInteger('a1')));
        $step('renameAttribute', fn () => $a->renameAttribute('movies', 'a1', 'a1r'));
        $step('renameAttribute again', fn () => $a->renameAttribute('movies', 'a1', 'a1r'));
        if ($engine !== 'sqlite') {
            $step('deleteAttribute', fn () => $a->deleteAttribute('movies', 'a2'));
            $step('deleteAttribute missing', fn () => $a->deleteAttribute('movies', 'nope'));
        }
        if ($engine !== 'mariadb' && $engine !== 'mysql') {
            $step('createIndex', fn () => $a->createIndex('movies', Index::key('idx_extra', ['extra2'])));
            $step('createIndex unique', fn () => $a->createIndex('movies', Index::unique('uq_a1r', ['a1r', '$updatedAt'])));
        }
        if (!($engine === 'postgres' && $shared) && $engine !== 'sqlite') {
            $step('renameIndex', fn () => $a->renameIndex('movies', 'idx_title', 'idx_title2'));
        }
        $step('deleteIndex', fn () => $a->deleteIndex('movies', 'uq_code'));
        if ($engine !== 'sqlite') {
            $step('deleteIndex missing', fn () => $a->deleteIndex('movies', 'nope'));
        }
        $step('createRelationship oneToOne', fn () => $a->createRelationship('movies', Relationship::oneToOne('people', key: 'star', twoWay: true, twoWayKey: 'starred')));
        $step('createRelationship oneToMany', fn () => $a->createRelationship('movies', Relationship::oneToMany('people', key: 'crew', twoWay: false, twoWayKey: 'crewOf')));
        $step('createRelationship manyToMany', fn () => $a->createRelationship('movies', Relationship::manyToMany('people', key: 'fans', twoWayKey: 'liked')));
        $step('updateRelationship oneToOne', fn () => $a->updateRelationship('movies', Relationship::oneToOne('people', key: 'star', twoWay: true, twoWayKey: 'starred'), RelationshipSide::Parent, new Update(key: 'lead', twoWayKey: 'leading')));
        $step('updateRelationship oneToMany child', fn () => $a->updateRelationship('movies', Relationship::oneToMany('people', key: 'crew', twoWayKey: 'crewOf'), RelationshipSide::Parent, new Update(twoWayKey: 'crewIn')));
        $step('deleteRelationship oneToOne', fn () => $a->deleteRelationship('movies', Relationship::oneToOne('people', key: 'lead', twoWay: true, twoWayKey: 'leading'), RelationshipSide::Child));
        $step('deleteRelationship oneToMany', fn () => $a->deleteRelationship('movies', Relationship::oneToMany('people', key: 'crew', twoWayKey: 'crewIn'), RelationshipSide::Parent));
        $step('analyzeCollection', fn () => $a->analyzeCollection('movies'));
        $step('getSizeOfCollection', fn () => $a->getSizeOfCollection('movies') >= 0);
        $step('getSizeOfCollectionOnDisk', fn () => $a->getSizeOfCollectionOnDisk('movies') >= 0);
        $after = schemaOf($a, 'movies');
        $step('deleteCollection', fn () => $a->deleteCollection('movies'));
        $step('deleteCollection missing', fn () => $a->deleteCollection('movies'));
        $step('createCollection people again', fn () => $a->createCollection('people', [], []));
        $out[] = ['engine' => $engine, 'shared' => $shared, 'steps' => $steps, 'schema' => $schema, 'after' => $after];
        try { $a->delete($name); } catch (Throwable) {}
    }
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
