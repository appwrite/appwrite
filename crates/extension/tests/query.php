<?php

// Parity corpus for the part of Utopia\Query the utopia extension has so far: the statement
// classifiers and the Type they return. Run it on the PHP classes and on the extension's
// (_APP_EXTENSION_LIBRARIES=query), and diff stdout: every line must match. Stderr names the
// implementation that ran.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Query\Classifier;
use Utopia\Query\Classifier\MongoDB;
use Utopia\Query\Classifier\MySQL;
use Utopia\Query\Classifier\PostgreSQL;
use Utopia\Query\Classifier\SQL;
use Utopia\Query\Type;

const NATIVE = [Type::class, Classifier::class, SQL::class, MySQL::class, PostgreSQL::class, MongoDB::class];

$internal = array_map(fn (string $class): bool => (new ReflectionClass($class))->isInternal(), NATIVE);
fwrite(STDERR, 'Utopia\\Query classifiers: ' . ($internal[0] ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$requested = in_array('query', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if (array_unique($internal) !== [$requested]) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' query, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

/** $value with every string that is not UTF-8 as its hex, so json_encode() cannot hide a byte. */
function exported(mixed $value): mixed
{
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8') ? $value : ['hex' => bin2hex($value)];
    }
    if (is_array($value)) {
        return array_map(exported(...), $value);
    }
    if ($value instanceof UnitEnum) {
        return ['enum' => $value::class . '::' . $value->name];
    }
    if (is_object($value)) {
        return ['class' => $value::class];
    }

    return $value;
}

/** The observable outcome of $run: its value or the exception it throws. */
function outcome(callable $run): array
{
    try {
        return ['value' => exported($run())];
    } catch (Throwable $e) {
        return ['throws' => $e::class];
    }
}

function emit(string $case, mixed $result): void
{
    echo json_encode([exported($case), $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
}

// --- SQL text ------------------------------------------------------------------------------

$keywords = [
    'SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'TABLE', 'VALUES',
    'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'TRUNCATE', 'GRANT', 'REVOKE', 'LOCK', 'CALL', 'DO',
    'BEGIN', 'START', 'COMMIT', 'ROLLBACK', 'SAVEPOINT', 'RELEASE', 'SET',
    'COPY', 'WITH', 'MERGE', 'REPLACE', 'USE', 'ANALYZE', 'VACUUM', 'PREPARE', 'EXECUTE', 'LISTEN', 'NOTIFY', 'FETCH',
    'SELECTX', 'SEL', 'A', 'SAVEPOINTS', '',
];

$statements = [
    '', ' ', "\n\t ", ';', '0', '()', '(SELECT 1)', '((select 1))', '1 SELECT',
    'select * from users where id = 1', 'SeLeCt 1', 'select', 'select;', 'select(1)', 'select/**/1', "select\n1",
    'insert into t values (1)', 'INSERT INTO t SELECT * FROM u', 'update t set a = 1', 'delete from t',
    'begin', 'BEGIN;', 'begin work', 'start transaction', 'START TRANSACTION READ ONLY', 'commit', 'rollback',
    'rollback to savepoint a', 'savepoint a', 'release savepoint a', 'set names utf8', "SET search_path = 'x'",
    '-- comment', "-- comment\nselect 1", "-- a\n-- b\n  update t set a = 1", '--', "--\n", '-', '- select 1',
    '/* c */ select 1', '/* c */', '/* unterminated select 1', '/**/', '/*/ select 1', '/* a */ /* b */ delete from t',
    "/* a\nb */\n-- c\n\tinsert into t values (1)", '/*! select 1 */ update t set a = 1', '# comment' . "\n" . 'select 1',
    "'select' update t set a = 1", "'it''s' select 1", "'unterminated select 1", "'a\\' select 1", "'a\\\\' select 1",
    '"select" delete from t', '"a""b" select 1', '"unterminated', '`select` insert into t values (1)', '`a``b` select 1',
    '`unterminated', '$$select$$ update t set a = 1', '$tag$ select $tag$ delete from t', '$tag$ unterminated', '$1 select',
    '$ select 1', '$$', '$a$b$a$ select 1', '$tag$x$other$ select 1',
    'copy t to stdout', 'COPY t FROM stdin', 'copy (select 1) to stdout', 'copy t', 'COPY t (a, b) FROM \'/tmp/x\'',
    'copy "from" to stdout', "copy t to '/tmp/from'", 'copy t from program \'cat to\'', 'COPY',
    'with a as (select 1) select * from a', 'WITH a AS (SELECT 1) INSERT INTO t SELECT * FROM a',
    'with a as (select 1) update t set a = 1', 'with a as (select 1) delete from t',
    'with a as (insert into t values (1) returning *) select * from a', 'with recursive a as (select 1) select 1',
    'with a as (select \'insert\') select 1', 'with a as (select 1), b as (select 2) select 1', 'with', 'with a',
    'with a as (select 1)', 'with a as (select "update") select 1', 'with a as (select 1) /* delete */ select 1',
    'with a as (select 1) -- insert' . "\n" . 'select 1', 'with a as (select $$delete$$) select 1',
    'WITH a AS (SELECT 1) MERGE INTO t USING a ON true WHEN MATCHED THEN DO NOTHING',
    'explain analyze delete from t', 'show tables', 'describe t', 'desc t', 'table t', 'values (1)',
    'create table t (a int)', 'drop table t', 'alter table t add b int', 'truncate t', 'grant all on t to u',
    'revoke all on t from u', 'lock tables t write', 'call p()', 'do 1', 'DO $$ BEGIN END $$',
    'select 1; delete from t', 'sélect 1', "select\x00 1", "\x00select 1", "\xff\xfe select 1", "sel\xffect 1",
    "\xef\xbb\xbfselect 1", "\xc2\xa0select 1", "\x0bselect 1", "\x0cselect 1", "\rselect 1", 'select' . str_repeat(' ', 5000) . '1',
    str_repeat('/* c */ ', 2000) . 'update t set a = 1', str_repeat('-- c' . "\n", 2000) . 'select 1',
    str_repeat('(', 500) . 'select 1', "'" . str_repeat('a', 10000) . "' select 1", str_repeat('x', 100000),
];
foreach ($keywords as $keyword) {
    foreach ([$keyword, strtolower($keyword), ucfirst(strtolower($keyword))] as $spelled) {
        array_push($statements, $spelled, $spelled . ' x', '  ' . $spelled . ';', $spelled . '(', "/* c */\n" . $spelled . "\tx", $spelled . '_x', 'x' . $spelled);
    }
}

// Seeded mutations: a byte changed, removed, inserted, or the statement cut short.
mt_srand(20261117);
$alphabet = " \t\n;'\"`$-/*#()\\\x00\xffabSELCTWIHsewith";
$seeds = $statements;
foreach ($seeds as $statement) {
    if ($statement === '' || strlen($statement) > 400) {
        continue;
    }
    for ($i = 0; $i < 6; $i++) {
        $at = mt_rand(0, strlen($statement) - 1);
        $byte = $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        $statements[] = match (mt_rand(0, 3)) {
            0 => substr_replace($statement, $byte, $at, 1),
            1 => substr_replace($statement, '', $at, 1),
            2 => substr_replace($statement, $byte, $at, 0),
            3 => substr($statement, 0, $at),
        };
    }
}
$statements = array_values(array_unique($statements));

$mysql = new MySQL();
$postgres = new PostgreSQL();
/** A PHP subclass: it supplies classify() and inherits the keyword logic. */
$custom = new class () extends SQL {
    public function classify(string $data): Type
    {
        return $this->classifySQL(strrev($data));
    }
};

foreach ($statements as $statement) {
    emit('sql ' . $statement, [
        outcome(fn () => $mysql->classifySQL($statement)),
        outcome(fn () => $postgres->classifySQL($statement)),
        outcome(fn () => $custom->classifySQL($statement)),
        outcome(fn () => $mysql->extractKeyword($statement)),
        outcome(fn () => $postgres->extractKeyword($statement)),
        outcome(fn () => $custom->extractKeyword($statement)),
        outcome(fn () => $custom->classify(strrev($statement))),
    ]);
}

// --- Wire messages -------------------------------------------------------------------------

$messages = ['', "\x00", 'Q', 'select 1', str_repeat("\x00", 4), str_repeat("\x00", 5), str_repeat("\x00", 6), str_repeat("\xff", 64)];
$sample = array_slice($statements, 0, 220);
foreach ($sample as $statement) {
    // MySQL: a 3-byte length, a sequence byte, the command, the text.
    foreach ([0x03, 0x16, 0x17, 0x18, 0x19, 0x1A, 0x00, 0x01, 0x02, 0x04, 0x0E, 0x1B, 0xFF] as $command) {
        $messages[] = substr(pack('V', strlen($statement) + 1), 0, 3) . "\x00" . chr($command) . $statement;
    }
    // PostgreSQL: the type, a 4-byte length, the text, a terminator (or none, or two statements).
    foreach (['Q', 'P', 'B', 'E', 'X', 'S', 'D', 'C', 'H', 'q', "\x00"] as $type) {
        $messages[] = $type . pack('N', strlen($statement) + 5) . $statement . "\x00";
    }
    $messages[] = 'Q' . pack('N', strlen($statement) + 4) . $statement;
    $messages[] = 'Q' . pack('N', 0) . $statement . "\x00delete from t\x00";
    $messages[] = 'Q' . pack('N', 0) . "\x00" . $statement;
}

/** A BSON document of string, int32, bool, double, null, document and array values. */
function bson(array $document): string
{
    $body = '';
    foreach ($document as $key => $value) {
        $key = (string) $key;
        $body .= match (true) {
            is_string($value) => "\x02" . $key . "\x00" . pack('V', strlen($value) + 1) . $value . "\x00",
            is_int($value) => "\x10" . $key . "\x00" . pack('V', $value),
            is_bool($value) => "\x08" . $key . "\x00" . ($value ? "\x01" : "\x00"),
            is_float($value) => "\x01" . $key . "\x00" . pack('e', $value),
            $value === null => "\x0A" . $key . "\x00",
            array_is_list($value) => "\x04" . $key . "\x00" . bson($value),
            default => "\x03" . $key . "\x00" . bson($value),
        };
    }

    return pack('V', 4 + strlen($body) + 1) . $body . "\x00";
}

function opMsg(string $document, int $opcode = 2013, int $kind = 0, int $flags = 0): string
{
    $body = pack('V', $flags) . chr($kind) . $document;

    return pack('V', 16 + strlen($body)) . pack('V', 1) . pack('V', 0) . pack('V', $opcode) . $body;
}

$commands = [
    'find', 'aggregate', 'count', 'distinct', 'listCollections', 'listDatabases', 'listIndexes', 'dbStats', 'collStats',
    'explain', 'getMore', 'serverStatus', 'buildInfo', 'connectionStatus', 'ping', 'isMaster', 'ismaster', 'hello',
    'insert', 'update', 'delete', 'findAndModify', 'create', 'drop', 'createIndexes', 'dropIndexes', 'dropDatabase',
    'renameCollection', 'commitTransaction', 'abortTransaction', 'startTransaction', 'endSessions', 'killCursors',
    'saslStart', 'unknownCommand', 'FIND', 'Find', '', 'f', 'findx', 'mapReduce', 'bulkWrite', 'getLastError', 'whatsmyuri',
];
$mongo = [];
foreach ($commands as $command) {
    $documents = [
        [$command => 'users', '$db' => 'app'],
        [$command => 1],
        [$command => 'users', 'startTransaction' => true, 'autocommit' => false],
        [$command => 'users', 'filter' => ['startTransaction' => true], '$db' => 'app'],
        [$command => 'users', 'documents' => [['a' => 1.5, 'b' => null, 'c' => [1, 2, 'x']]], 'lsid' => ['id' => 'x'], 'txnNumber' => 1, 'startTransaction' => true],
        [$command => 'users', 'startTransaction' => false],
        [$command => 'users', 'startTransactionX' => true, 'xstartTransaction' => true],
        ['startTransaction' => true, $command => 'users'],
    ];
    foreach ($documents as $document) {
        $encoded = bson($document);
        array_push($mongo, opMsg($encoded), opMsg($encoded, 2004), opMsg($encoded, 2013, 1), opMsg($encoded, 2013, 0, 2));
    }
}
array_push(
    $mongo,
    opMsg(''),
    opMsg(pack('V', 5) . "\x00"),
    opMsg(pack('V', 4)),
    opMsg(pack('V', 0x7fffffff) . "\x02find\x00"),
    opMsg(pack('V', 20) . "\x02find"),
    opMsg(pack('V', 20) . "\x02find\x00" . pack('V', 0x7fffffff) . 'users'),
    opMsg(pack('V', 30) . "\x05find\x00" . pack('V', 3) . "\x00abc" . "\x08startTransaction\x00\x01\x00"),
    opMsg(pack('V', 40) . "\x0Bfind\x00a\x00i\x00" . "\x08startTransaction\x00\x01\x00"),
    opMsg(pack('V', 60) . "\x0Cfind\x00" . pack('V', 2) . "a\x00" . str_repeat('x', 12) . "\x08startTransaction\x00\x01\x00"),
    opMsg(pack('V', 40) . "\x7Ffind\x00" . "\x08startTransaction\x00\x01\x00"),
    opMsg(pack('V', 40) . "\x11find\x00" . str_repeat("\x00", 8) . "\x08startTransaction\x00\x01\x00"),
    opMsg(pack('V', 40) . "\x13find\x00" . str_repeat("\x00", 16) . "\x08startTransaction\x00\x01\x00"),
    opMsg(pack('V', 40) . "\x07find\x00" . str_repeat("\x00", 12) . "\x08startTransaction\x00\x01\x00"),
    opMsg(pack('V', 40) . "\xFFfind\x00" . "\x08startTransaction\x00\x01\x00"),
);
foreach ($mongo as $message) {
    $messages[] = $message;
    for ($i = 0; $i < 3; $i++) {
        $at = mt_rand(0, strlen($message) - 1);
        $messages[] = substr($message, 0, $at);
        $messages[] = substr_replace($message, chr(mt_rand(0, 255)), $at, 1);
    }
}
for ($i = 0; $i < 3000; $i++) {
    $messages[] = random_bytes_seeded(mt_rand(0, 80));
}

function random_bytes_seeded(int $length): string
{
    $bytes = '';
    for ($i = 0; $i < $length; $i++) {
        $bytes .= chr(mt_rand(0, 255));
    }

    return $bytes;
}

$document = new MongoDB();
foreach ($messages as $index => $message) {
    emit('message ' . $index . ' ' . bin2hex(substr($message, 0, 24)), [
        outcome(fn () => $mysql->classify($message)),
        outcome(fn () => $postgres->classify($message)),
        outcome(fn () => $document->classify($message)),
    ]);
}

// --- The classes themselves ----------------------------------------------------------------

emit('cases', array_map(fn (Type $type): array => [$type->name, $type->value], Type::cases()));
emit('from', array_map(fn (string $value): array => outcome(fn () => Type::tryFrom($value)), ['read', 'write', 'transaction_begin', 'transaction_end', 'transaction', 'unknown', 'Read', '', 'READ']));
emit('from unknown value', outcome(fn () => Type::from('nope')));
emit('same case', outcome(fn () => $mysql->classifySQL('select 1') === Type::Read));
emit('backed enum', outcome(fn () => [Type::Read instanceof BackedEnum, (new ReflectionEnum(Type::class))->getBackingType()?->getName()]));
emit('instances', outcome(fn () => [
    $mysql instanceof Classifier, $mysql instanceof SQL, $postgres instanceof Classifier, $postgres instanceof SQL,
    $document instanceof Classifier, $document instanceof SQL, $custom instanceof Classifier, $custom instanceof SQL,
]));
emit('kinds', outcome(fn () => array_map(function (string $class): array {
    $reflection = new ReflectionClass($class);

    return [$reflection->isInterface(), $reflection->isAbstract(), $reflection->isEnum(), $reflection->getParentClass() ? $reflection->getParentClass()->getName() : null, $reflection->getInterfaceNames() === [] ? [] : array_values(array_intersect($reflection->getInterfaceNames(), [Classifier::class]))];
}, NATIVE)));
emit('public methods', outcome(function (): array {
    $methods = [];
    foreach ([Classifier::class, SQL::class, MySQL::class, PostgreSQL::class, MongoDB::class] as $class) {
        $names = array_map(fn (ReflectionMethod $method): string => $method->getName(), (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC));
        $names = array_values(array_diff($names, ['__construct']));
        sort($names);
        $methods[$class] = $names;
    }

    return $methods;
}));
emit('abstract is not instantiable', outcome(fn () => new SQL()));
emit('interface is not instantiable', outcome(fn () => new Classifier()));
emit('a class that implements the interface', outcome(function (): array {
    $own = new class () implements Classifier {
        public function classify(string $data): Type
        {
            return $data === '' ? Type::Unknown : Type::Write;
        }
    };

    return [$own instanceof Classifier, $own->classify(''), $own->classify('x')];
}));
emit('a subclass of a concrete classifier', outcome(function (): array {
    $own = new class () extends MySQL {
        public function classify(string $data): Type
        {
            return $data === 'x' ? Type::Transaction : parent::classify($data);
        }
    };

    return [$own->classify('x'), $own->classify("\x09\x00\x00\x00\x03select 1"), $own->classifySQL('delete from t'), $own->extractKeyword(' set x')];
}));
emit('equal and cloned', outcome(fn () => [$mysql == new MySQL(), $mysql == $postgres, clone $mysql == $mysql, (array) $mysql, (array) $document]));
emit('typed parameter', outcome(fn () => (fn (Classifier $classifier, string $data): Type => $classifier->classify($data))($postgres, "Q\x00\x00\x00\x0dSELECT 1\x00")));

// The scalar arguments PHP converts for a string parameter, and the ones it refuses.
$stringable = new class () {
    public function __toString(): string
    {
        return 'delete from t';
    }
};
foreach (['int' => 1, 'float' => 1.5, 'true' => true, 'false' => false, 'stringable' => $stringable, 'null' => null, 'array' => [], 'object' => new stdClass()] as $name => $argument) {
    emit('argument ' . $name, [
        outcome(fn () => $mysql->classifySQL($argument)),
        outcome(fn () => $postgres->extractKeyword($argument)),
        outcome(fn () => $mysql->classify($argument)),
        outcome(fn () => $document->classify($argument)),
    ]);
}
emit('missing argument', [outcome(fn () => $mysql->classify()), outcome(fn () => $postgres->classifySQL()), outcome(fn () => $document->classify())]);

// Known differences of the extension, each a waiver in crates/extension/README.md.
$enumIsFinal = (new ReflectionClass(Type::class))->isFinal();
if ($enumIsFinal === $requested) {
    fwrite(STDERR, 'Type is ' . ($enumIsFinal ? '' : 'not ') . 'final: the known difference changed' . PHP_EOL);
    exit(1);
}
