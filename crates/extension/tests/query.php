<?php

// Parity corpus for the part of Utopia\Query the utopia extension has so far: the statement
// classifiers with the Type they return, the tokenizers, Query, the AST's parser and serializers, and the schemas. Run it on the PHP classes and on the extension's
// (_APP_EXTENSION_LIBRARIES=query), and diff stdout: every line must match. Stderr names the
// implementation that ran.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Query\AST;
use Utopia\Query\Builder;
use Utopia\Query\Builder\ParsedQuery;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Classifier;
use Utopia\Query\Classifier\MongoDB;
use Utopia\Query\Classifier\MySQL;
use Utopia\Query\Classifier\PostgreSQL;
use Utopia\Query\Classifier\SQL;
use Utopia\Query\Compiler;
use Utopia\Query\CursorDirection;
use Utopia\Query\Method;
use Utopia\Query\NullsPosition;
use Utopia\Query\OrderDirection;
use Utopia\Query\Query;
use Utopia\Query\Schema;
use Utopia\Query\Schema\ColumnType;
use Utopia\Query\Schema\ForeignKeyAction;
use Utopia\Query\Schema\IndexType;
use Utopia\Query\Schema\ParameterDirection;
use Utopia\Query\Schema\TriggerEvent;
use Utopia\Query\Schema\TriggerTiming;
use Utopia\Query\Tokenizer;
use Utopia\Query\Tokenizer\Token;
use Utopia\Query\Tokenizer\TokenType;
use Utopia\Query\Type;

const NATIVE = [
    Type::class, Classifier::class, SQL::class, MySQL::class, PostgreSQL::class, MongoDB::class,
    Tokenizer\Tokenizer::class, Tokenizer\MySQL::class, Tokenizer\MariaDB::class, Tokenizer\PostgreSQL::class,
    Tokenizer\SQLite::class, Tokenizer\ClickHouse::class, Query::class,
    AST\Grammar::class, AST\Parser::class, AST\Serializer::class, AST\Serializer\MySQL::class, AST\Serializer\MariaDB::class,
    AST\Serializer\PostgreSQL::class, AST\Serializer\SQLite::class, AST\Serializer\ClickHouse::class,
    Schema::class, Schema\SQL::class, Schema\MySQL::class, Schema\PostgreSQL::class, Schema\SQLite::class, Schema\ClickHouse::class, Schema\MongoDB::class,
];

// Query is the package's class over the extension's NativeQuery; every other one is the extension's own.
$internal = array_map(fn (string $class): bool => $class === Query::class ? get_parent_class($class) === 'Utopia\\Query\\NativeQuery' : (new ReflectionClass($class))->isInternal(), NATIVE);
fwrite(STDERR, 'Utopia\\Query classifiers, tokenizers and Query: ' . ($internal[0] ? 'utopia extension' : 'PHP') . PHP_EOL);
// A run meant for the extension that gets the PHP classes would compare PHP with PHP and pass.
$requested = in_array('query', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if (array_unique($internal) !== [$requested]) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' query, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

// Warnings and deprecations are not part of the corpus, except where a case collects them.
set_error_handler(fn (): bool => true);

/** $value with every string that is not UTF-8 as its hex, so json_encode() cannot hide a byte. */
function exported(mixed $value): mixed
{
    if (is_string($value)) {
        return mb_check_encoding($value, 'UTF-8') ? $value : ['hex' => bin2hex($value)];
    }
    if (is_array($value)) {
        return array_map(exported(...), $value);
    }
    if ($value instanceof Token) {
        return [$value->type->name, exported($value->value), $value->position];
    }
    if ($value instanceof Query) {
        return ['query' => $value::class, 'state' => [
            $value->getMethod()->name, exported($value->getAttribute()), exported($value->getValues()), exported($value->getAlias()),
            $value->isAliasDefaulted(), $value->onArray(), exported($value->getAttributeType()),
        ]];
    }
    if (is_object($value) && str_starts_with($value::class, 'Utopia\\Query\\AST\\') && ! $value instanceof AST\Serializer && ! $value instanceof AST\Parser) {
        return ['node' => $value::class, 'fields' => exported(get_object_vars($value))];
    }
    if ($value instanceof Statement) {
        return ['statement' => [exported($value->query), exported($value->bindings), $value->readOnly, exported($value->namedBindings)]];
    }
    if ($value instanceof ParsedQuery) {
        return ['parsed' => exported(get_object_vars($value))];
    }
    if (is_float($value)) {
        return ['float' => var_export($value, true)];
    }
    if ($value instanceof stdClass || $value instanceof ArrayObject) {
        return ['class' => $value::class, 'fields' => exported((array) $value)];
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
    } catch (Utopia\Query\Exception $e) {
        return ['throws' => $e::class, 'message' => exported($e->getMessage())];
    } catch (TypeError $e) {
        // A call that both omits an argument and passes a wrong one is a TypeError in PHP and
        // its subclass ArgumentCountError in the extension, which counts before it converts.
        return ['throws' => TypeError::class];
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

// --- Tokenizers ----------------------------------------------------------------------------

$sqlTexts = $statements;
array_push(
    $sqlTexts,
    '1',
    '12',
    '1.5',
    '.5',
    '1.',
    '1e3',
    '1E-3',
    '1e',
    '1e+',
    '1.2.3',
    '0x1F',
    '1_000',
    '-1',
    '+1',
    '1..2',
    '.',
    '..',
    '1.e3',
    '9223372036854775808',
    '?',
    '??',
    '?1',
    ':name',
    ':1',
    '::',
    'a::int',
    ':',
    ':_a1',
    '$1',
    '$12',
    '$',
    '$a',
    '$$',
    '$1a',
    '= <> != <= >= < > || && | & ^ ~ ! % + - * / @ #',
    '<=>',
    '<->',
    '<#>',
    '@>',
    '<@',
    '?|',
    '?&',
    '->',
    '->>',
    '#>',
    '#>>',
    '@@',
    '!~*',
    '<<',
    '>>',
    ':=',
    'a @> b',
    'a <@ b',
    'a <=> b',
    'a <-> b',
    'a <#> b',
    'a ?| b',
    'a ?& b',
    'a ? b',
    'a < = > b',
    'a <- > b',
    'a @ > b',
    'a ?  | b',
    '@>@>',
    '<@>',
    '<=>>',
    '<<->',
    "'a'",
    "''",
    "'a''b'",
    "'a\\'b'",
    "'a\\\\'",
    "'a\\",
    "'",
    "'a",
    "'a''",
    'E\'a\\n\'',
    "N'a'",
    "x'ff'",
    "'a' 'b'",
    "'\n'",
    "'\x00'",
    "'\xff'",
    '`a`',
    '``',
    '`a``b`',
    '`a\\`b`',
    '`',
    '`a',
    '"a"',
    '""',
    '"a""b"',
    '"a\\"b"',
    '"',
    '"a',
    '[a]',
    '`a`.`b`.*',
    '"a"."b"',
    'a.b.c',
    'a . b',
    'a.*',
    '.a',
    'a.',
    'select',
    'SELECT',
    'Select',
    'true',
    'FALSE',
    'null',
    'Null',
    'nulls',
    'truex',
    '_a',
    'a_b1',
    '1a',
    'a1',
    'é',
    'aé',
    "a\xffb",
    'select_',
    'from1',
    '--',
    '-- a',
    "-- a\nb",
    '--a',
    '- -',
    '---',
    '/**/',
    '/* a */',
    '/* a',
    '/*/',
    '/* * / */',
    '/* /* a */ */',
    '*/',
    '/',
    '//',
    '#',
    '# a',
    "# a\nb",
    "a # b\nc",
    "'#' # '\n'#'",
    '`#` # `',
    '"#" # "',
    "a#b",
    '##',
    "#\n#",
    "\\'# a",
    "'a\\'# b'# c",
    " \t\r\n",
    "a\tb\rc\nd",
    "\x0b",
    "\x0c",
    "\x00",
    "a\x00b",
    "\xc2\xa0",
    ';',
    ';;',
    '()',
    '(,)',
    ',',
    '*',
    '**',
    'a*b',
    'count(*)',
    'SELECT a, b FROM t WHERE c = ? AND d IN (:x, :y) ORDER BY e DESC LIMIT 10 OFFSET $1;',
    'INSERT INTO `t` (`a`, `b`) VALUES (1, \'x\'), (2.5, NULL) ON DUPLICATE KEY UPDATE `a` = VALUES(`a`)',
    'SELECT "a"."b"::text, c->>\'d\', e @> \'{"f":1}\'::jsonb FROM "t" WHERE g <-> $1 < 0.5',
    'SELECT toStartOfHour(time) AS h, count() FROM events PREWHERE tenant = 1 GROUP BY h WITH TOTALS FORMAT JSON',
    'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT \'\')',
);
$sqlTexts = array_values(array_unique($sqlTexts));
foreach ($sqlTexts as $text) {
    if ($text === '' || strlen($text) > 300) {
        continue;
    }
    for ($i = 0; $i < 3; $i++) {
        $at = mt_rand(0, strlen($text) - 1);
        $byte = $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        $sqlTexts[] = match (mt_rand(0, 2)) {
            0 => substr_replace($text, $byte, $at, 1),
            1 => substr_replace($text, $byte, $at, 0),
            2 => substr($text, 0, $at),
        };
    }
}
$sqlTexts = array_values(array_unique($sqlTexts));

$tokenizers = [
    'base' => new Tokenizer\Tokenizer(),
    'mysql' => new Tokenizer\MySQL(),
    'mariadb' => new Tokenizer\MariaDB(),
    'postgres' => new Tokenizer\PostgreSQL(),
    'sqlite' => new Tokenizer\SQLite(),
    'clickhouse' => new Tokenizer\ClickHouse(),
];
foreach ($sqlTexts as $text) {
    $read = array_map(fn (Tokenizer\Tokenizer $tokenizer): array => outcome(fn () => $tokenizer->tokenize($text)), $tokenizers);
    emit('tokens ' . (strlen($text) > 200 ? substr($text, 0, 200) . '... ' . strlen($text) . ' ' . md5($text) : $text), [
        strlen($text) > 2000 ? md5(json_encode($read['base'])) : $read['base'],
        $read['mysql'] === $read['base'] ? 'as base' : $read['mysql'],
        $read['mariadb'] === $read['mysql'] ? 'as mysql' : $read['mariadb'],
        $read['postgres'] === $read['base'] ? 'as base' : $read['postgres'],
        $read['sqlite'] === $read['postgres'] ? 'as postgres' : $read['sqlite'],
        $read['clickhouse'] === $read['base'] ? 'as base' : $read['clickhouse'],
        outcome(fn () => Tokenizer\Tokenizer::filter($tokenizers['mysql']->tokenize($text))),
    ]);
}

$tokens = $tokenizers['base']->tokenize("select 1 -- a\n/* b */ from t");
emit('tokens are the package\'s objects', outcome(fn () => [
    $tokens[0] instanceof Token, (new ReflectionClass(Token::class))->isInternal(), (new ReflectionClass(TokenType::class))->isInternal(),
    $tokens[0]->type === TokenType::Keyword, $tokens[0] == new Token(TokenType::Keyword, 'SELECT', 0), array_is_list($tokens),
]));
emit('a token is readonly', outcome(function () use ($tokens) {
    $tokens[0]->value = 'x';
}));
emit('filter keeps the same objects', outcome(function () use ($tokens): array {
    $filtered = Tokenizer\Tokenizer::filter($tokens);

    return [count($tokens), count($filtered), $filtered[0] === $tokens[0], array_is_list($filtered), $filtered];
}));
emit('filter from a dialect', outcome(fn () => [
    count(Tokenizer\MySQL::filter($tokens)), count(Tokenizer\MariaDB::filter($tokens)), count(Tokenizer\PostgreSQL::filter($tokens)),
    count(Tokenizer\SQLite::filter($tokens)), count(Tokenizer\ClickHouse::filter($tokens)),
]));
emit('filter of nothing', outcome(fn () => Tokenizer\Tokenizer::filter([])));
emit('filter of a keyed array', outcome(fn () => array_keys(Tokenizer\Tokenizer::filter(['a' => $tokens[0], 7 => $tokens[1], 'b' => $tokens[2]]))));
emit('filter of tokens made by hand', outcome(fn () => Tokenizer\Tokenizer::filter([
    new Token(TokenType::Whitespace, 'not blank', 5), new Token(TokenType::LineComment, '', 0), new Token(TokenType::BlockComment, 'x', 1),
    new Token(TokenType::Eof, ' ', 2), new Token(TokenType::String, '-- a', 3),
])));
emit('tokenizer hierarchy', outcome(fn () => array_map(fn (object $tokenizer): array => [
    $tokenizer instanceof Tokenizer\Tokenizer, $tokenizer instanceof Tokenizer\MySQL, $tokenizer instanceof Tokenizer\MariaDB,
    $tokenizer instanceof Tokenizer\PostgreSQL, $tokenizer instanceof Tokenizer\SQLite, $tokenizer instanceof Tokenizer\ClickHouse,
    (new ReflectionClass($tokenizer))->getParentClass() ? (new ReflectionClass($tokenizer))->getParentClass()->getName() : null,
    (new ReflectionClass($tokenizer))->isFinal(), (new ReflectionClass($tokenizer))->isAbstract(),
], $tokenizers)));
emit('quote character, from inside a subclass', outcome(function (): array {
    $quotes = [];
    foreach ([Tokenizer\Tokenizer::class, Tokenizer\MySQL::class, Tokenizer\MariaDB::class, Tokenizer\PostgreSQL::class, Tokenizer\SQLite::class, Tokenizer\ClickHouse::class] as $class) {
        $quotes[] = (new ReflectionMethod($class, 'getIdentifierQuoteChar'))->isProtected();
        $quotes[] = eval('return (new class () extends \\' . $class . ' { public function quote(): string { return $this->getIdentifierQuoteChar(); } })->quote();');
    }

    return $quotes;
}));
emit('a subclass that changes tokenize()', outcome(function (): array {
    $own = new class () extends Tokenizer\PostgreSQL {
        public function tokenize(string $sql): array
        {
            return array_slice(parent::tokenize($sql), 0, -1);
        }
    };

    return [$own->tokenize('a <-> b'), $own instanceof Tokenizer\Tokenizer];
}));
emit('tokenizers equal and cloned', outcome(fn () => [
    new Tokenizer\MySQL() == new Tokenizer\MySQL(), $tokenizers['mysql'] == $tokenizers['mariadb'], clone $tokenizers['sqlite'] == $tokenizers['sqlite'],
    (clone $tokenizers['postgres'])->tokenize('"a"'),
]));
foreach (['int' => 12, 'float' => 1.5, 'true' => true, 'false' => false, 'stringable' => $stringable ?? null, 'null' => null, 'array' => [], 'object' => new stdClass()] as $name => $argument) {
    emit('tokenize argument ' . $name, [outcome(fn () => $tokenizers['base']->tokenize($argument)), outcome(fn () => Tokenizer\Tokenizer::filter($argument))]);
}
emit('tokenize without an argument', [outcome(fn () => $tokenizers['base']->tokenize()), outcome(fn () => Tokenizer\Tokenizer::filter())]);

// --- Query ---------------------------------------------------------------------------------

/** What a query answers about itself, beyond its state. */
function described(Query $query): array
{
    return [
        exported($query),
        outcome(fn () => $query->toArray()),
        outcome(fn () => $query->toString()),
        outcome(fn () => $query->shape()),
        [$query->isNested(), $query->isSpatialQuery(), $query->isNestedJoin()],
        outcome(fn () => $query->getJoinOnQueries()),
        outcome(fn () => $query->getValue('fallback')),
    ];
}

/** A subclass with a constructor of its own and factories that widen, as Utopia\Audit\Query has. */
class CorpusQuery extends Query
{
    public string $note = 'kept';

    public function __construct(Method|string $method, string $attribute = '', array $values = [], string $alias = '')
    {
        parent::__construct($method, $attribute, $values, $alias);
    }

    #[\Override]
    public static function equal(string $attribute, mixed $value): static
    {
        return new static(Method::Equal, $attribute, is_array($value) ? $value : [$value]);
    }

    #[\Override]
    public static function groupBy(array|string $attributes): static
    {
        return is_string($attributes) ? new static(Method::GroupBy, $attributes, []) : parent::groupBy($attributes);
    }

    public function peek(): array
    {
        return [$this->method, $this->attribute, $this->attributeType, $this->onArray, $this->values, $this->alias, $this->aliasDefaulted];
    }
}

/** A subclass that declares nothing. */
class PlainQuery extends Query
{
}

$recording = new class () implements Compiler {
    public function compileFilter(Query $query): string
    {
        return 'filter ' . $query->getMethod()->value;
    }

    public function compileOrder(Query $query): string
    {
        return 'order ' . $query->getMethod()->value;
    }

    public function compileLimit(Query $query): string
    {
        return 'limit ' . $query->getMethod()->value;
    }

    public function compileOffset(Query $query): string
    {
        return 'offset ' . $query->getMethod()->value;
    }

    public function compileSelect(Query $query): string
    {
        return 'select ' . $query->getMethod()->value;
    }

    public function compileCursor(Query $query): string
    {
        return 'cursor ' . $query->getMethod()->value;
    }

    public function compileAggregate(Query $query): string
    {
        return 'aggregate ' . $query->getMethod()->value;
    }

    public function compileGroupBy(Query $query): string
    {
        return 'group ' . $query->getMethod()->value;
    }

    public function compileJoin(Query $query): string
    {
        return 'join ' . $query->getMethod()->value;
    }
};

// Every method, constructed directly on the class and on both subclasses.
foreach (Method::cases() as $method) {
    foreach ([Query::class, CorpusQuery::class, PlainQuery::class] as $class) {
        emit('new ' . $class . ' ' . $method->name, [
            outcome(fn () => described(new $class($method))),
            outcome(fn () => described(new $class($method->value, 'attr', ['a', 1, 1.5, true, null, ['k' => 'v']], 'alias'))),
            outcome(fn () => described(new $class($method, 'same', [], 'same'))),
            outcome(fn () => described(new $class($method, values: [new $class(Method::Equal, 'x', [1]), 'name']))),
            outcome(fn () => (new $class($method, 'attr', [1]))->compile($recording)),
            outcome(fn () => Query::isMethod($method->value)),
        ]);
    }
}
foreach (['', 'nope', 'EQUAL', 'Equal', ' equal', "equal\x00", "\xff", 1, 1.5, true, false, null, [], new stdClass(), Method::Equal, NullsPosition::First] as $index => $given) {
    emit('method argument ' . $index, [
        outcome(fn () => described(new Query($given))),
        outcome(fn () => described((new Query('equal'))->setMethod($given))),
        outcome(fn () => Query::isMethod($given)),
    ]);
}
foreach ([['equal', 1], ['equal', 1.5], ['equal', true], ['equal', null], ['equal', []], ['equal', 'a', 'x'], ['equal', 'a', null], ['equal', 'a', [], 7], ['equal', 'a', [], null], ['equal', 'a', [], []], []] as $index => $arguments) {
    emit('constructor arguments ' . $index, outcome(fn () => described(new Query(...$arguments))));
}

// The factories, each with arguments it takes and arguments it must refuse.
$nested = [Query::equal('a', [1]), Query::greaterThan('b', 2)];
$point = [1.5, -2];
$pool = [
    'a', '', '0', '*', '$id', "bin\xff", 0, 7, -1, 1.5, 0.0, NAN, INF, true, false, null,
    [], [1], ['a', 'b'], ['k' => 'v'], [[1, 2], [3, 4]], $nested, [Query::and($nested)], [1, 'a', null, 1.5],
    new stdClass(), new ArrayObject(['$id' => 'x']), NullsPosition::First, NullsPosition::Last, Method::Equal, Query::limit(1),
];
$factories = [];
foreach ((new ReflectionClass(Query::class))->getMethods(ReflectionMethod::IS_STATIC) as $factory) {
    if ($factory->isPublic()) {
        $factories[$factory->getName()] = [$factory->getNumberOfRequiredParameters(), $factory->getNumberOfParameters()];
    }
}
ksort($factories);
emit('static methods', $factories);
$skipped = ['parse', 'parseQuery', 'parseQueries', 'fingerprint', 'getByType', 'getCursorQueries', 'groupByType', 'merge', 'diff', 'validate', 'isMethod', 'contains'];
foreach ($factories as $name => [$required, $all]) {
    if (in_array($name, $skipped, true)) {
        continue;
    }
    $calls = [[], ['a'], ['a', 'b'], ['a', 'b', 'c'], ['a', 'b', 'c', 'd'], ['a', 'b', 'c', 'd', 'e'], ['a', [1, 2]], ['a', $point, 10, true], [['x', 'y']], [$nested], ['a', $nested], ['t', 'u', $nested], ['a', 1, 2], [3], [3, 4], ['a', NullsPosition::Last]];
    // Seeded by the method, so one implementation's methods do not shift another's arguments.
    mt_srand(crc32($name));
    for ($i = 0; $i < 60; $i++) {
        $arguments = [];
        for ($j = mt_rand(0, $all); $j > 0; $j--) {
            $arguments[] = $pool[mt_rand(0, count($pool) - 1)];
        }
        $calls[] = $arguments;
    }
    foreach ($calls as $index => $arguments) {
        // More arguments than the method declares are a known difference, asserted at the end.
        if (count($arguments) > $all) {
            continue;
        }
        emit('factory ' . $name . ' ' . $index, [
            exported($arguments),
            outcome(fn () => described(Query::$name(...$arguments))),
            outcome(fn () => exported(CorpusQuery::$name(...$arguments))),
            outcome(fn () => exported(PlainQuery::$name(...$arguments))),
        ]);
    }
}
emit('contains is deprecated', outcome(function (): array {
    $raised = [];
    set_error_handler(function (int $level, string $message) use (&$raised): bool {
        $raised[] = [$level, $message];

        return true;
    });
    try {
        return [exported(Query::contains('a', [1])), exported(PlainQuery::contains('a', [1])), exported(Query::containsString('a', [1])), $raised];
    } finally {
        restore_error_handler();
    }
}));
emit('named arguments', outcome(fn () => [
    exported(new Query(method: 'limit', values: [5])), exported(Query::equal(values: [1], attribute: 'a')), exported(Query::count(alias: 'n')),
    exported(Query::orderAsc(nulls: NullsPosition::First)), exported(Query::page(page: 2, perPage: 10)), exported(Query::raw(sql: 'x = ?', bindings: [1])),
    exported(Query::on(left: 'a', right: 'b')), exported(Query::join(collection: 'c', alias: 'x', on: [Query::on('a', 'b')])),
    exported(Query::parse(query: '{"method":"raw","attribute":"x"}', allowRaw: true)), exported(Query::getByType(queries: [Query::limit(1)], types: [Method::Limit], clone: false)),
    exported(Query::merge(queriesA: [], queriesB: [Query::limit(1)])), exported(Query::validate(queries: [Query::equal('a', [1])], allowedAttributes: ['b'])),
    exported((new Query('equal'))->getValue(default: 'd')), exported(Query::distanceEqual('a', [1, 2], 5, meters: true)),
]));

// Parsing: JSON that is a query, JSON that is not, and text that is not JSON.
$documents = [
    '', ' ', 'null', 'true', '1', '1.5', '"text"', '[]', '{}', '[1,2]', '{', '}', '{"method":}', "{\"method\":\"equal\"}\x00", "\xff", '{"method":"equal",}',
    '{"method":"equal"}', '{"method":"equal","attribute":"a","values":[1]}', '{"method":"equal","attribute":"a","values":[1],"alias":"x"}',
    '{"method":"equal","attribute":null,"values":null,"alias":null}', '{"method":null}', '{"method":1}', '{"method":true}', '{"method":[]}', '{"method":{}}',
    '{"method":"nope"}', '{"method":"EQUAL"}', '{"method":""}', '{"method":"equal","attribute":1}', '{"method":"equal","attribute":[]}', '{"method":"equal","attribute":true}',
    '{"method":"equal","values":1}', '{"method":"equal","values":"a"}', '{"method":"equal","values":{}}', '{"method":"equal","values":{"k":"v"}}', '{"method":"equal","values":{"3":"a","1":"b"}}',
    '{"method":"equal","alias":1}', '{"method":"equal","alias":[]}', '{"method":"equal","extra":1}', '{"method":"equal","attribute":"0","values":[0,"0",0.0,false,null,"",[]]}',
    '{"method":"equal","attribute":"a","values":[1.0,1e2,-0.0,9223372036854775807,9223372036854775808,1.7976931348623157e308,"é","😀"]}',
    '{"method":"raw","attribute":"1=1"}', '{"method":"raw","attribute":"a = ?","values":[1]}', '{"method":"and","values":[{"method":"raw","attribute":"x"}]}',
    '{"method":"distinct"}', '{"method":"distinct","attribute":"a"}', '{"method":"distinct","values":[1]}', '{"method":"distinct","attribute":"","values":[]}',
    '{"method":"and","values":[{"method":"equal","attribute":"a","values":[1]},{"method":"or","values":[{"method":"isNull","attribute":"b"}]}]}',
    '{"method":"and","values":[1]}', '{"method":"and","values":["a"]}', '{"method":"and","values":[[]]}', '{"method":"and","values":[{"method":"nope"}]}', '{"method":"and","values":{"k":{"method":"limit","values":[1]}}}',
    '{"method":"or","values":[]}', '{"method":"elemMatch","attribute":"tags","values":[{"method":"equal","attribute":"a","values":[1]}]}', '{"method":"relationship","attribute":"r","values":[{"method":"limit","values":[1]}]}',
    '{"method":"having","values":[{"method":"greaterThan","attribute":"n","values":[1]}]}', '{"method":"union","values":[{"method":"equal","attribute":"a","values":[1]}]}', '{"method":"unionAll","values":[]}',
    '{"method":"count"}', '{"method":"count","attribute":"*","values":["total"]}', '{"method":"count","attribute":"*","alias":"n","values":["legacy"]}', '{"method":"sum","attribute":"a","values":[1]}', '{"method":"sum","attribute":"a","values":[null,"x"]}',
    '{"method":"avg","attribute":"a","values":{"0":"zero"}}', '{"method":"min","attribute":"a","values":{"k":"v"}}', '{"method":"bitXor","attribute":"a","alias":"x"}',
    '{"method":"join","attribute":"users"}', '{"method":"join","attribute":"users","values":[]}', '{"method":"join","attribute":"users","values":["u"]}',
    '{"method":"join","attribute":"users","alias":"u","values":[{"method":"on","values":["a","=","b"]}]}', '{"method":"join","attribute":"users","values":[{"method":"on","values":["a","=","b"]}]}',
    '{"method":"join","attribute":"users","values":["a","=","b"]}', '{"method":"join","attribute":"users","values":["a","=","b","u"]}', '{"method":"join","attribute":"users","values":["a","=","b","u","extra"]}',
    '{"method":"join","attribute":"users","values":["u",{"method":"on","values":["a","=","b"]}]}', '{"method":"join","attribute":"users","values":["u","v",{"method":"on","values":["a","=","b"]}]}',
    '{"method":"join","attribute":"users","values":[1]}', '{"method":"join","attribute":"users","values":[null]}', '{"method":"join","attribute":"users","values":[true]}', '{"method":"join","attribute":"users","values":[1.5]}',
    '{"method":"join","attribute":"","values":[{"method":"on","values":["a","=","b"]}]}', '{"method":"join","attribute":"users","alias":"users","values":[{"method":"on","values":["a","=","b"]}]}',
    '{"method":"join","attribute":"users","values":[{"method":"limit","values":[1]}]}', '{"method":"join","attribute":"users","values":[{"method":"and","values":[{"method":"on","values":["a","=","b"]},{"method":"equal","attribute":"c","values":[1]}]}]}',
    '{"method":"join","attribute":"users","values":[{"method":"or","values":[{"method":"limit","values":[1]}]}]}', '{"method":"join","attribute":"users","values":[{"method":"and","values":[1]}]}',
    '{"method":"leftJoin","attribute":"users","values":["u.id","=","o.user"]}', '{"method":"rightJoin","attribute":"t","alias":"x","values":[{"method":"equal","attribute":"a","values":[1]}]}', '{"method":"fullOuterJoin","attribute":"t","values":["a","<","b","z"]}',
    '{"method":"crossJoin","attribute":"t"}', '{"method":"crossJoin","attribute":"t","values":["x"]}', '{"method":"crossJoin","attribute":"t","alias":"y","values":["x"]}', '{"method":"crossJoin","attribute":"t","values":[{"method":"on","values":["a","=","b"]}]}', '{"method":"crossJoin","attribute":""}',
    '{"method":"naturalJoin","attribute":"t","values":["x","y"]}', '{"method":"naturalJoin","attribute":"t","values":["a","=","b"]}', '{"method":"on","values":["a","=","b"]}',
    '{"method":"limit","values":[10]}', '{"method":"limit","values":["10"]}', '{"method":"offset","values":[1.5]}', '{"method":"cursorAfter","values":[{"$id":"x"}]}', '{"method":"cursorBefore","values":["x"]}',
    '{"method":"select","values":["a","b"]}', '{"method":"orderAsc","attribute":"a","values":["FIRST"]}', '{"method":"orderDesc"}', '{"method":"orderRandom"}', '{"method":"groupBy","values":["a"]}', '{"method":"groupByTimeBucket","attribute":"t","values":["nope"]}',
    '{"method":"distanceEqual","attribute":"p","values":[[[1,2],5,true]]}', '{"method":"jsonPath","attribute":"j","values":["$.a","=",{"k":[1]}]}', '{"method":"vectorCosine","attribute":"v","values":[[0.1,0.2]]}',
    str_repeat('{"method":"and","values":[', 40) . '{"method":"limit","values":[1]}' . str_repeat(']}', 40),
    str_repeat('{"method":"and","values":[', 600) . '{"method":"limit","values":[1]}' . str_repeat(']}', 600),
    ' {"method" : "equal" , "attribute" : "a" , "values" : [ 1 ] } ', '{"method":"equal","method":"limit","values":[2]}', '{"METHOD":"equal"}', "{\"method\":\"equal\",\"attribute\":\"a\xffb\"}",
];
foreach (Method::cases() as $method) {
    $documents[] = json_encode(['method' => $method->value]);
    $documents[] = json_encode(['method' => $method->value, 'attribute' => 'a', 'values' => ['x', 1], 'alias' => 'z']);
    $documents[] = json_encode(['method' => $method->value, 'attribute' => 'a', 'values' => [['method' => 'on', 'values' => ['l', '=', 'r']]]]);
    $documents[] = json_encode(['method' => $method->value, 'values' => [['method' => 'equal', 'attribute' => 'b', 'values' => [2]], ['method' => 'limit', 'values' => [1]]]]);
}
foreach ($documents as $index => $document) {
    $name = 'parse ' . $index . ' ' . (strlen($document) > 160 ? substr($document, 0, 160) . '... ' . md5($document) : $document);
    $decoded = json_decode($document, true);
    emit($name, [
        outcome(fn () => described(Query::parse($document))),
        outcome(fn () => exported(Query::parse($document, true))),
        outcome(fn () => exported(CorpusQuery::parse($document))),
        outcome(fn () => exported(PlainQuery::parse($document, true))),
        is_array($decoded) ? outcome(fn () => exported(Query::parseQuery($decoded))) : 'not an array',
        is_array($decoded) ? outcome(fn () => exported(CorpusQuery::parseQuery($decoded, true))) : 'not an array',
        outcome(fn () => Query::parse($document)->toString() === Query::parse(Query::parse($document)->toString())->toString()),
        outcome(fn () => Query::fingerprint([$document])),
    ]);
}
emit('parse arguments', [
    outcome(fn () => exported(Query::parse(1))), outcome(fn () => exported(Query::parse(null))), outcome(fn () => exported(Query::parse([]))), outcome(fn () => exported(Query::parse())),
    outcome(fn () => exported(Query::parse('{"method":"raw"}', 1))), outcome(fn () => exported(Query::parse('{"method":"raw"}', 'yes'))), outcome(fn () => exported(Query::parse('{"method":"raw"}', []))),
    outcome(fn () => exported(Query::parseQuery('x'))), outcome(fn () => exported(Query::parseQuery(null))), outcome(fn () => exported(Query::parseQuery())),
    outcome(fn () => exported(Query::parseQuery(['method' => 'equal', 'values' => [new stdClass(), NullsPosition::First, Query::limit(1)]]))),
    outcome(fn () => exported(Query::parseQuery(['method' => 'and', 'values' => [Query::limit(1)]]))),
    outcome(fn () => exported(Query::parseQuery(['method' => Method::Equal]))),
    outcome(fn () => exported(Query::parseQuery([0 => 'equal', 'method' => 'limit', 'values' => ['k' => 1, 5 => 2]]))),
]);
emit('parseQueries', [
    outcome(fn () => exported(Query::parseQueries([]))),
    outcome(fn () => exported(Query::parseQueries(['{"method":"limit","values":[1]}', '{"method":"offset","values":[2]}']))),
    outcome(fn () => exported(CorpusQuery::parseQueries(['k' => '{"method":"limit","values":[1]}', 9 => '{"method":"equal","attribute":"a","values":[1]}']))),
    outcome(fn () => exported(Query::parseQueries(['{"method":"limit","values":[1]}', '{']))),
    outcome(fn () => exported(Query::parseQueries(['{"method":"raw","attribute":"x"}']))),
    outcome(fn () => exported(Query::parseQueries(['{"method":"raw","attribute":"x"}'], true))),
    outcome(fn () => exported(Query::parseQueries([1]))), outcome(fn () => exported(Query::parseQueries([null]))), outcome(fn () => exported(Query::parseQueries([[]]))),
    outcome(fn () => exported(Query::parseQueries([Query::limit(1)]))), outcome(fn () => exported(Query::parseQueries('x'))),
]);

// A query's values are the PHP values it was given: the same objects, shared and changed in place.
$child = Query::equal('a', [1]);
$parent = Query::and([$child, Query::or([Query::isNull('b')])]);
emit('nested queries are the same objects', outcome(function () use ($child, $parent): array {
    $facts = [$parent->getValues()[0] === $child, $parent->getValues() === $parent->getValues()];
    $parent->getValues()[0]->setAttribute('changed');
    $facts[] = $child->getAttribute();
    $facts[] = $parent->toArray();
    $child->setAttribute('a');

    return $facts;
}));
emit('clone copies nested queries', outcome(function () use ($child, $parent): array {
    $copy = clone $parent;
    $copy->getValues()[0]->setAttribute('copy');
    $copy->getValues()[1]->getValues()[0]->setAttribute('deep');
    $sub = new CorpusQuery(Method::And, '', [new CorpusQuery(Method::Equal, 'x', [1]), 'text', ['k' => Query::limit(1)], new stdClass()]);
    $sub->note = 'changed';
    $subCopy = clone $sub;

    return [
        $child->getAttribute(), exported($parent), exported($copy), $copy == $parent, $copy->getValues()[0] === $child,
        $subCopy::class, $subCopy->note, $subCopy->getValues()[0] === $sub->getValues()[0], $subCopy->getValues()[0]::class,
        $subCopy->getValues()[2]['k'] === $sub->getValues()[2]['k'], $subCopy->getValues()[3] === $sub->getValues()[3], $subCopy == $sub,
    ];
}));
emit('setters return the query', outcome(function (): array {
    $query = new CorpusQuery('equal', 'a', [1]);
    $facts = [
        $query->setMethod(Method::Limit) === $query, $query->setMethod('offset') === $query, $query->setAttribute('b') === $query,
        $query->setValues(['x' => 1, 2]) === $query, $query->setValue([1, 2]) === $query, $query->setOnArray(true), $query->setAttributeType('string'),
        exported($query), $query->peek()[4],
    ];
    $facts[] = exported((new Query('join', 'same', [], 'same'))->setAttribute('other'));
    $facts[] = exported((new Query('equal', 'same', [], 'same'))->setMethod('join'));

    return $facts;
}));
foreach (['int' => 3, 'float' => 1.5, 'true' => true, 'null' => null, 'array' => ['a'], 'object' => new stdClass(), 'stringable' => $stringable ?? null] as $name => $argument) {
    emit('setter argument ' . $name, [
        outcome(fn () => exported((new Query('equal'))->setAttribute($argument))), outcome(fn () => exported((new Query('equal'))->setValues($argument))),
        outcome(fn () => exported((new Query('equal'))->setValue($argument))), outcome(fn () => exported((new Query('equal'))->setAttributeType($argument))),
        outcome(function () use ($argument) {
            $query = new Query('equal');
            $query->setOnArray($argument);

            return $query->onArray();
        }),
    ]);
}
emit('getValue', outcome(fn () => [
    (new Query('equal'))->getValue(), (new Query('equal'))->getValue('d'), (new Query('equal', 'a', [null]))->getValue('d'), (new Query('equal', 'a', [0]))->getValue('d'),
    (new Query('equal', 'a', [false, 1]))->getValue('d'), (new Query('equal', 'a', ['k' => 'v']))->getValue('d'), (new Query('equal', 'a', [1 => 'one']))->getValue('d'),
    (new Query('equal', 'a', ['0' => 'zero']))->getValue(), exported((new Query('equal', 'a', [Query::limit(1)]))->getValue()),
]));
emit('a subclass reads and keeps its own state', outcome(function (): array {
    $query = CorpusQuery::equal('a', 'scalar');
    $facts = [$query::class, $query->note, exported($query->peek()), exported(CorpusQuery::groupBy('one')), exported(CorpusQuery::groupBy(['a', 'b'])), CorpusQuery::groupBy(['a'])::class];
    $facts[] = exported(CorpusQuery::and([PlainQuery::limit(1), Query::limit(2)]));
    $facts[] = [CorpusQuery::limit(1) instanceof Query, PlainQuery::createdAfter('x')::class, CorpusQuery::page(2)[1]::class, CorpusQuery::join('t', 'u', [Query::on('a', 'b')])::class];
    $facts[] = [CorpusQuery::parse('{"method":"and","values":[{"method":"limit","values":[1]}]}')->getValues()[0]::class, CorpusQuery::parse('{"method":"join","attribute":"t","values":["a","=","b"]}')->getValues()[0]::class];

    return $facts;
}));
emit('what the engine shows of a query', outcome(function (): array {
    $query = Query::equal('a', [1, 'x']);
    ob_start();
    var_dump($query, new CorpusQuery('limit', '', [5]));
    $dumped = preg_replace('/#\d+/', '#n', ob_get_clean());

    return [
        $dumped, array_map(fn (string $key): string => str_replace("\0", '~', $key), array_keys((array) $query)), json_encode($query), $query == Query::equal('a', [1, 'x']), $query == Query::equal('a', [1, 'y']),
        $query == PlainQuery::equal('a', [1, 'x']), $query != Query::limit(1), property_exists($query, 'values'), property_exists(Query::class, 'alias'),
        (new ReflectionClass(Query::class))->isFinal(), (new ReflectionClass(Query::class))->isAbstract(), Query::DEFAULT_ALIAS,
    ];
}));
emit('protected state stays protected', [
    outcome(fn () => (Query::limit(1))->method), outcome(fn () => (Query::limit(1))->values),
    outcome(function () {
        $query = Query::limit(1);
        $query->attribute = 'x';
    }),
    outcome(function () {
        $query = Query::limit(1);
        $query->undeclared = 'x';
    }),
]);

// Where Query.php calls a method through `static` or on a query object, a subclass's own runs.
class LateQuery extends Query
{
    /** @var list<string> */
    public static array $calls = [];

    #[\Override]
    public static function parse(string $query, bool $allowRaw = false): static
    {
        self::$calls[] = 'parse';

        return parent::parse($query, $allowRaw);
    }

    #[\Override]
    public static function parseQuery(array $query, bool $allowRaw = false): static
    {
        self::$calls[] = 'parseQuery ' . (is_string($query['method'] ?? null) ? $query['method'] : '?');

        return parent::parseQuery($query, $allowRaw);
    }

    #[\Override]
    public static function isMethod(Method|string $value): bool
    {
        self::$calls[] = 'isMethod';

        return $value === 'custom' || $value instanceof Method || parent::isMethod($value);
    }

    #[\Override]
    public static function on(string $left, string $right, string $operator = '='): static
    {
        self::$calls[] = 'on';

        return parent::on($left, $right, $operator);
    }

    #[\Override]
    public static function lessThan(string $attribute, mixed $value): static
    {
        self::$calls[] = 'lessThan';

        return parent::lessThan($attribute, $value);
    }

    #[\Override]
    public static function greaterThan(string $attribute, mixed $value): static
    {
        self::$calls[] = 'greaterThan';

        return parent::greaterThan($attribute, $value);
    }

    #[\Override]
    public static function between(string $attribute, mixed $start, mixed $end): static
    {
        self::$calls[] = 'between';

        return parent::between($attribute, $start, $end);
    }

    #[\Override]
    public static function limit(int $value): static
    {
        self::$calls[] = 'limit';

        return parent::limit($value);
    }

    #[\Override]
    public static function offset(int $value): static
    {
        self::$calls[] = 'offset';

        return parent::offset($value);
    }

    #[\Override]
    public static function validate(array $queries, array $allowedAttributes): array
    {
        self::$calls[] = 'validate';

        return parent::validate($queries, $allowedAttributes);
    }

    #[\Override]
    public function toArray(): array
    {
        return ['late' => parent::toArray()];
    }

    #[\Override]
    public function shape(): string
    {
        return 'late(' . parent::shape() . ')';
    }
}

/** $run's outcome and the overridden methods it reached, in order. */
function late(callable $run): array
{
    LateQuery::$calls = [];
    $outcome = outcome($run);

    return [$outcome, LateQuery::$calls];
}

foreach ([
    '{"method":"equal","attribute":"a","values":[1]}', '{"method":"and","values":[{"method":"equal","attribute":"a","values":[1]},{"method":"or","values":[{"method":"isNull","attribute":"b"}]}]}',
    '{"method":"join","attribute":"t","values":["a","=","b"]}', '{"method":"join","attribute":"t","alias":"u","values":[{"method":"on","values":["a","=","b"]}]}',
    '{"method":"custom"}', '{"method":"nope"}', '{"method":"and","values":["text"]}', '{"method":"count","values":["n"]}', '{', '[]',
] as $index => $document) {
    emit('late parse ' . $index . ' ' . $document, [
        late(fn () => exported(LateQuery::parse($document))),
        late(fn () => exported(LateQuery::parseQuery(json_decode($document, true) ?? []))),
        late(fn () => exported(LateQuery::parseQueries([$document, $document]))),
        late(fn () => LateQuery::fingerprint([$document])),
        late(fn () => LateQuery::parse($document)->toString()),
    ]);
}
emit('late factories', [
    late(fn () => exported(LateQuery::createdBefore('x'))), late(fn () => exported(LateQuery::createdAfter('x'))), late(fn () => exported(LateQuery::updatedBefore('x'))),
    late(fn () => exported(LateQuery::updatedAfter('x'))), late(fn () => exported(LateQuery::createdBetween('x', 'y'))), late(fn () => exported(LateQuery::updatedBetween('x', 'y'))),
    late(fn () => exported(LateQuery::page(3, 10))), late(fn () => exported(LateQuery::page(1))), late(fn () => exported(Query::page(3, 10))), late(fn () => exported(Query::createdBefore('x'))),
]);
emit('late helpers', [
    late(fn () => LateQuery::validate([LateQuery::and([LateQuery::equal('bad', [1]), LateQuery::or([LateQuery::equal('worse', [1])])])], ['a'])),
    late(fn () => Query::validate([LateQuery::and([LateQuery::equal('bad', [1])])], ['a'])),
    late(fn () => LateQuery::equal('a', [1])->toString()), late(fn () => LateQuery::equal('a', [1])->toArray()), late(fn () => LateQuery::equal('a', [1])->shape()),
    late(fn () => Query::and([LateQuery::equal('a', [1]), Query::equal('b', [2])])->toArray()), late(fn () => Query::and([LateQuery::equal('a', [1]), Query::equal('b', [2])])->toString()),
    late(fn () => Query::and([LateQuery::equal('a', [1]), Query::equal('b', [2])])->shape()), late(fn () => LateQuery::and([Query::equal('a', [1])])->shape()),
    late(fn () => Query::fingerprint([LateQuery::equal('a', [1]), Query::equal('b', [2]), '{"method":"limit","values":[1]}'])),
    late(fn () => LateQuery::fingerprint([LateQuery::equal('a', [1]), '{"method":"limit","values":[1]}'])),
    late(fn () => exported(Query::diff([LateQuery::equal('a', [1]), Query::equal('a', [1])], [Query::equal('a', [1])]))),
    late(fn () => exported(Query::diff([Query::equal('a', [1])], [LateQuery::equal('a', [1])]))),
    late(fn () => exported(LateQuery::groupByType([LateQuery::limit(1), LateQuery::equal('a', [1])]))), late(fn () => exported(clone LateQuery::and([LateQuery::limit(1)]))),
]);

// The database library's Query extends this one: its own parseQuery() decodes children given as
// JSON strings, its toArray() writes a cursor document as its id, and it reads LOGICAL_TYPES.
$document = new Utopia\Database\Document(['$id' => 'doc1', 'name' => 'x']);
emit('database query', [
    outcome(fn () => Utopia\Database\Query::cursorAfter($document)->toString()),
    outcome(fn () => Utopia\Database\Query::and([Utopia\Database\Query::equal('a', [1]), Utopia\Database\Query::cursorBefore($document)])->toArray()),
    outcome(fn () => exported(Utopia\Database\Query::parse('{"method":"or","values":["{\"method\":\"equal\",\"attribute\":\"a\",\"values\":[1]}",{"method":"isNull","attribute":"b"}]}'))),
    outcome(fn () => exported(Utopia\Database\Query::parseQuery(['method' => 'and', 'values' => ['{"method":"limit","values":[1]}']]))),
    outcome(fn () => exported(Utopia\Database\Query::parse('{"method":"relationship","attribute":"r","values":["{\"method\":\"limit\",\"values\":[1]}"]}'))),
    outcome(fn () => exported(Utopia\Database\Query::parseQueries(['{"method":"equal","attribute":"a","values":[1]}', '{"method":"stddev","attribute":"a"}']))),
    outcome(fn () => Utopia\Database\Query::parse('{"method":"nope"}')), outcome(fn () => Utopia\Database\Query::parse('{')), outcome(fn () => Utopia\Database\Query::and(['not a query'])->toArray()),
    outcome(fn () => [Utopia\Database\Query::isMethod(Method::Equal), Utopia\Database\Query::isMethod('equal'), Utopia\Database\Query::isMethod('nope')]),
    outcome(fn () => Utopia\Database\Query::fingerprint([Utopia\Database\Query::and([Utopia\Database\Query::equal('a', [1])]), '{"method":"limit","values":[1]}'])),
    outcome(fn () => Utopia\Database\Query::and([Utopia\Database\Query::equal('b', [1]), Utopia\Database\Query::having([Utopia\Database\Query::equal('a', [1])])])->shape()),
    outcome(fn () => exported(Utopia\Database\Query::groupByType([Utopia\Database\Query::limit(5), Utopia\Database\Query::cursorAfter($document), Utopia\Database\Query::equal('a', [1]), Utopia\Database\Query::orderAsc('a')]))),
    outcome(fn () => exported(Utopia\Database\Query::page(2, 10))), outcome(fn () => exported(Utopia\Database\Query::createdAfter('2026-01-01'))),
    outcome(fn () => exported(Utopia\Database\Query::stddev('a', 's'))), outcome(fn () => exported(Utopia\Database\Query::variance('a'))),
    outcome(fn () => exported(clone Utopia\Database\Query::and([Utopia\Database\Query::equal('a', [1])]))),
    outcome(fn () => Utopia\Database\Query::validate([Utopia\Database\Query::and([Utopia\Database\Query::equal('bad', [1])])], ['a'])),
    outcome(fn () => exported(Query::diff([Utopia\Database\Query::cursorAfter($document)], [Utopia\Database\Query::cursorAfter(new Utopia\Database\Document(['$id' => 'doc1']))]))),
    outcome(fn () => [get_class(Utopia\Database\Query::limit(1)), Utopia\Database\Query::limit(1) instanceof Query, Utopia\Database\Query::parse('{"method":"and","values":[{"method":"limit","values":[1]}]}')->getValues()[0]::class]),
]);

// The helpers that read lists of queries.
$lists = [
    [],
    [Query::equal('a', [1]), Query::limit(10), Query::offset(5), Query::orderAsc('a'), Query::orderDesc('b', NullsPosition::Last), Query::orderRandom()],
    [Query::limit('7'), Query::limit(3), new Query('offset', '', ['x']), new Query('offset', '', [2.9])],
    [new Query('limit', '', ['12abc']), new Query('offset', '', [' 4 ']), new Query('limit', '', [1e30])],
    [new Query('limit', '', [null]), new Query('limit'), new Query('offset', '', [true]), new Query('limit', '', ['1e3']), new Query('offset', '', ['0x1A']), new Query('limit', '', [[1]])],
    [Query::cursorAfter(['$id' => 'a']), Query::cursorBefore(['$id' => 'b'])], [Query::cursorBefore(new ArrayObject(['$id' => 'z'])), Query::cursorAfter(['$id' => 'a'])], [new Query('cursorAfter')],
    [Query::select(['a', 'b']), Query::select(['c']), Query::count('*', 'n'), Query::sum('a'), Query::bitXor('f'), Query::groupBy(['a', 'b']), Query::groupBy(['c']), Query::groupByTimeBucket('t', '1h'), new Query('groupByTimeBucket', 't')],
    [Query::having([Query::greaterThan('n', 1)]), Query::distinct(), Query::join('t', 'u', [Query::on('a', 'b')]), Query::crossJoin('v', ''), Query::union([Query::equal('a', [1])]), Query::unionAll([])],
    [Query::and([Query::equal('a', [1]), Query::or([Query::isNull('b'), Query::equal('zzz', [1])])]), Query::elemMatch('tags', [Query::equal('bad', [1])]), Query::relationship('r', [Query::equal('ignored', [1])])],
    [Query::equal('', [1]), Query::equal('*', [1]), Query::equal('0', [1]), Query::exists(['nope']), Query::notExists('nope'), Query::raw('x = 1'), Query::search('missing', 'q'), Query::jsonPath('missing', '$.a', '=', 1)],
    ['text', 1, null, Query::limit(1), new stdClass(), [Query::limit(2)]],
    ['k' => Query::limit(1), 9 => Query::equal('a', [1]), 'z' => Query::offset(3)],
    [new CorpusQuery('limit', '', [4]), PlainQuery::equal('a', [1]), new CorpusQuery('select', '', ['a'])],
];
foreach ($lists as $index => $list) {
    emit('lists ' . $index, [
        exported($list),
        outcome(fn () => exported(Query::groupByType($list))),
        outcome(fn () => exported(Query::getByType($list, [Method::Limit, Method::Equal, Method::Select]))),
        outcome(fn () => exported(Query::getByType($list, []))),
        outcome(fn () => exported(Query::getCursorQueries($list))),
        outcome(fn () => Query::validate($list, ['a', 'b', 'n', 't', 'tags'])),
        outcome(fn () => Query::validate($list, [])),
        outcome(fn () => Query::fingerprint($list)),
        outcome(fn () => exported(Query::merge($list, [Query::limit(99), Query::equal('m', [1]), Query::cursorAfter(['$id' => 'm'])]))),
        outcome(fn () => exported(Query::merge([Query::limit(1), 'k' => Query::offset(2), Query::equal('a', [1])], $list))),
        outcome(fn () => exported(Query::diff($list, [Query::limit(10), Query::equal('a', [1])]))),
        outcome(fn () => exported(Query::diff([Query::limit(10), Query::equal('a', [1]), Query::limit(1)], $list))),
        outcome(fn () => exported(CorpusQuery::groupByType($list))),
    ]);
}
emit('filtered queries are copies unless asked', outcome(function (): array {
    $limit = Query::limit(1);
    $cursor = Query::cursorAfter(['$id' => 'a']);

    return [
        Query::getByType([$limit], [Method::Limit])[0] === $limit, Query::getByType([$limit], [Method::Limit], false)[0] === $limit, Query::getByType([$limit], [Method::Limit], true)[0] == $limit,
        Query::getCursorQueries([$cursor])[0] === $cursor, Query::getCursorQueries([$cursor], false)[0] === $cursor, Query::groupByType([$limit, Query::equal('a', [1])])->filters[0] === $limit,
        Query::merge([$limit], [$cursor])[1] === $cursor, Query::diff([$limit], [])[0] === $limit,
        exported(Query::getByType([$limit, $cursor], ['limit', Method::CursorAfter, 5, null, CursorDirection::After])),
        Query::groupByType([$cursor])->cursorDirection, Query::groupByType([Query::cursorBefore(['$id' => 'a'])])->cursorDirection, Query::groupByType([])->cursorDirection,
    ];
}));
emit('helper arguments', [
    outcome(fn () => Query::groupByType('x')), outcome(fn () => Query::groupByType()), outcome(fn () => Query::getByType([], 'x')), outcome(fn () => Query::getByType('x', [])),
    outcome(fn () => Query::getByType([], [], 'yes')), outcome(fn () => Query::merge([], 'x')), outcome(fn () => Query::diff('x', [])), outcome(fn () => Query::validate([], 'x')),
    outcome(fn () => Query::validate([Query::equal('a', [1])], ['a' => 'k', 5 => 'a'])), outcome(fn () => Query::validate([Query::groupBy([1, 'a', null, ['x']])], ['a', '1'])),
    outcome(fn () => Query::fingerprint('x')), outcome(fn () => Query::fingerprint([1])), outcome(fn () => Query::fingerprint([null])), outcome(fn () => Query::fingerprint([[]])),
    outcome(fn () => Query::fingerprint(['{"method":"raw","attribute":"x"}'])), outcome(fn () => Query::fingerprint(['{'])),
    outcome(fn () => Query::fingerprint([Query::equal('a', [1]), Query::equal('a', [2])]) === Query::fingerprint([Query::equal('a', ['x'])  , '{"method":"equal","attribute":"a","values":[9]}'])),
    outcome(fn () => Query::fingerprint([Query::and([Query::equal('b', [1]), Query::equal('a', [1])])]) === Query::fingerprint([Query::and([Query::equal('a', [1]), Query::equal('b', [1])])])),
    outcome(fn () => (new Query('equal', 'a', [1]))->compile(new stdClass())), outcome(fn () => (new Query('equal', 'a', [1]))->compile('x')), outcome(fn () => (new Query('equal', 'a', [1]))->compile()),
]);
emit('page', array_map(fn (array $arguments): array => outcome(fn () => exported(Query::page(...$arguments))), [[1], [2], [3, 10], [0], [-1], [1, 0], [1, -5], ['2', '10'], [2.0], [2.5], ['x'], [null], [], [PHP_INT_MAX, 2], [true]]));

// `toString()` writes its own JSON for plain values and leaves the rest to `json_encode()`: both
// have to escape, refuse and nest as PHP does.
$encoded = [];
foreach (['', '0', 'a/b', 'quo"te', 'back\\slash', "tab\tnl\n\x01\x7f", 'é ü 日本 😀', "\xff bad", "<a href='x'>&</a>", "\u{2028}\u{2029}", '1.5', str_repeat('x', 300), "nul\0byte"] as $text) {
    $encoded[] = outcome(fn () => Query::equal($text, [$text])->toString());
    $encoded[] = outcome(fn () => Query::crossJoin($text === '' ? 't' : $text, $text)->toString());
    $encoded[] = outcome(fn () => Query::and([Query::equal('a', [$text, 1]), Query::or([Query::search($text, $text)])])->toString());
}
foreach ([null, true, false, 0, -1, PHP_INT_MAX, PHP_INT_MIN, 1.5, 0.1 + 0.2, NAN, [], [1, 2], ['a' => 1], new stdClass(), Query::limit(1)] as $held) {
    $encoded[] = outcome(fn () => (new Query('equal', 'a', [$held, 'x', $held]))->toString());
    $encoded[] = outcome(fn () => Query::and([new Query('equal', 'a', ['k' => $held])])->toString());
}
$deep = Query::equal('a', [1]);
for ($level = 0; $level < 300; $level++) {
    $deep = Query::and([$deep]);
    if (in_array($level, [10, 63, 64, 65, 200, 255, 256, 299], true)) {
        $encoded[] = outcome(fn () => md5($deep->toString()));
    }
}
emit('toString encodings', $encoded);

// A query that holds itself. Cloning one ends in the engine's error for infinite recursion on both.
$looped = Query::and([Query::equal('a', [1])]);
$looped->setValues([$looped]);
emit('a query that holds itself', [
    outcome(fn () => clone $looped),
    outcome(fn () => Query::groupByType([$looped])),
    outcome(fn () => Query::getByType([$looped], [Method::And])),
]);

// --- AST -----------------------------------------------------------------------------------

$grammarWords = [
    '', 'a', 'A1', '_', '_a', '1a', 'a b', 'a-b', 'a.b', 'COUNT', 'count', "a\n", ' a', 'a ', "\xff", "a\xffb", 'é', '0', 'a(', 'a()',
    'int', 'INT', 'varchar(255)', 'VARCHAR (255)', 'decimal(10, 2)', 'decimal(10,2)', 'decimal()', 'decimal(', 'decimal)', 'double precision',
    'double  precision', "double\tprecision", "double\nprecision", ' double', 'double ', 'timestamp with time zone', 'a(b)c', 'a(b))', 'a((b)',
    'numeric(1', '(1)', ' (1)', 'a (1)', "a\t(1)", 'a(1) ', 'a(b c)', 'a(;)', 'a(1);drop', '1int', 'int 1', 'int 1a', 'unsigned big int', "a\v b", "a\f(1)",
];
emit('grammar', array_map(fn (string $word): array => [exported($word), outcome(fn () => AST\Grammar::isIdentifier($word)), outcome(fn () => AST\Grammar::isType($word))], $grammarWords));
emit('grammar arguments', [
    outcome(fn () => AST\Grammar::isIdentifier(1)), outcome(fn () => AST\Grammar::isType(1.5)), outcome(fn () => AST\Grammar::isIdentifier(null)),
    outcome(fn () => AST\Grammar::isType([])), outcome(fn () => AST\Grammar::isIdentifier()), outcome(fn () => (new ReflectionClass(AST\Grammar::class))->isFinal()),
]);

$serializers = [
    'base' => new AST\Serializer(),
    'mysql' => new AST\Serializer\MySQL(),
    'mariadb' => new AST\Serializer\MariaDB(),
    'postgres' => new AST\Serializer\PostgreSQL(),
    'sqlite' => new AST\Serializer\SQLite(),
    'clickhouse' => new AST\Serializer\ClickHouse(),
];
$parser = new AST\Parser();

/** What every serializer makes of a statement or an expression. */
function serialized(array $serializers, mixed $node): array
{
    return array_map(fn (AST\Serializer $serializer): array => outcome(fn () => $node instanceof AST\Statement\Select ? $serializer->serialize($node) : $serializer->serializeExpression($node)), $serializers);
}

$selects = [
    'select 1', 'select *', 'select * from users', 'select a, b from t', 'select t.a, s.t.b, t.*, s.t.* from s.t', 'select a as x, b y from t as u',
    'select distinct a from t', 'select all a from t', 'select a from t where a = 1', 'select a from t where a = 1 and b = 2 or c = 3',
    'select a from t where (a = 1 or b = 2) and c = 3', 'select a from t where not a = 1', 'select a from t where not (a = 1 and b = 2)',
    'select a from t where a <> 1 and b != 2 and c < 3 and d > 4 and e <= 5 and f >= 6', 'select a + b * c - d / e % f from t', 'select (a + b) * c from t',
    'select a - (b - c), a - b - c, a / (b * c), (a or b) and c, a or (b and c) from t', 'select -a, +a, - -a, -(a + b), not a from t', 'select a || b || c from t',
    'select a from t where a is null', 'select a from t where a is not null', 'select a from t where a is true', 'select a from t where a is not false',
    'select a from t where a in (1, 2, 3)', 'select a from t where a not in (1)', 'select a from t where a in (select b from u)', 'select a from t where a not in (select b from u where c = 1)',
    'select a from t where a in ()', 'select a from t where a between 1 and 2', 'select a from t where a not between 1 and 2 and b = 3', 'select a from t where a between b + 1 and c * 2',
    'select a from t where a like \'x%\'', 'select a from t where a not like \'x%\'', 'select a from t where a ilike \'x\'', 'select a from t where a not ilike \'x\'',
    'select a from t where exists (select 1 from u)', 'select a from t where not exists (select 1 from u)', 'select (select max(b) from u) from t', 'select a from (select a from t) as s',
    'select a from (select a from t) s', 'select a from (select a from t)', 'select count(*), count(distinct a), sum(a), max(a), coalesce(a, b, 1), now(), f() from t',
    'select count(*) filter (where a > 1) from t', 'select Count(a), lower(Upper(a)) from t', 'select cast(a as int), cast(a as varchar(255)), cast(a as decimal(10, 2)), cast(a as double precision) from t',
    'select cast(a as) from t', 'select cast(a as 1) from t', 'select cast(a as int;) from t', 'select a::int from t', 'select a::varchar(10)::text from t',
    'select case when a = 1 then \'x\' end from t', 'select case when a = 1 then \'x\' when a = 2 then \'y\' else \'z\' end from t', 'select case a when 1 then \'x\' else \'y\' end from t',
    'select case end from t', 'select case when a then b from t', 'select case a else 1 end from t',
    'select a from t join u on t.id = u.id', 'select a from t inner join u on t.id = u.id', 'select a from t left join u on t.id = u.id', 'select a from t left outer join u on t.id = u.id',
    'select a from t right join u on t.id = u.id', 'select a from t right outer join u on a = b', 'select a from t full join u on a = b', 'select a from t full outer join u on a = b',
    'select a from t cross join u', 'select a from t natural join u', 'select a from t join u', 'select a from t, u', 'select a from t join u on a = b join v on c = d left join (select 1) w on true',
    'select a from t join u using (id)', 'select a from t outer join u on a = b', 'select a from t left u on a = b',
    'select a from t group by a', 'select a, b from t group by a, b having count(*) > 1', 'select a from t having a > 1', 'select a from t order by a', 'select a from t order by a asc, b desc',
    'select a from t order by a nulls first, b desc nulls last', 'select a from t order by a nulls', 'select a from t order by a nulls middle', 'select a from t order by rand()', 'select a from t order by 1',
    'select a from t limit 10', 'select a from t limit 10 offset 5', 'select a from t offset 5', 'select a from t limit ? offset ?', 'select a from t limit :n offset $1', 'select a from t limit 5, 10',
    'select a from t limit', 'select a from t limit a + 1', 'select a from t where a = ? and b = :name and c = $2', 'select ?, :a, $1',
    'with c as (select 1) select * from c', 'with c (a, b) as (select 1, 2) select a from c', 'with recursive c as (select 1) select * from c', 'with a as (select 1), b as (select 2) select * from a, b',
    'with c as select 1 select 2', 'with c as (select 1)', 'with select 1', 'with c () as (select 1) select 1', 'with c (a,) as (select 1) select 1',
    'select row_number() over () from t', 'select row_number() over (partition by a order by b) from t', 'select sum(a) over (partition by a, b order by c desc nulls last rows between unbounded preceding and current row) from t',
    'select sum(a) over (order by b range between 1 preceding and 1 following) from t', 'select sum(a) over (rows unbounded preceding) from t', 'select sum(a) over (rows between current row and unbounded following) from t',
    'select sum(a) over (groups between 1 preceding and 2 following) from t', 'select sum(a) over w from t window w as (partition by a)', 'select sum(a) over w, max(a) over v from t window w as (order by a), v as (partition by b order by c)',
    'select sum(a) over (rows between) from t', 'select sum(a) over (rows 1) from t', 'select sum(a) over (partition a) from t', 'select sum(a) over from t', 'select a from t window w as',
    'select count(*) filter (where a > 1) over (partition by b) from t',
    'select `a`, `b c`, `d``e` from `t` where `t`.`a` = 1', 'select "a", "b c", "d""e" from "t" where "t"."a" = 1', 'select `select`, `from` from `where`', 'select a from t as `u v`',
    "select 'a', 'it''s', 'a\\\\b', 'a\\'b', '', ' ', 'é', '\xff', 'a\nb' from t", 'select 1, 1.5, .5, 1., 1e3, 1.5e-3, 0, 007, 9223372036854775807, 9223372036854775808, 99999999999999999999, 0x1f, 1e999 from t',
    'select true, false, null, TRUE, False, Null from t', 'select a from t where a = true and b = null', "select é from t where naïve = 'x'", "select a from t where b = '\xf0\x9f\x98\x80'", "select \xff from t",
    'select a from t; select b from u', 'select a from t;', 'select a from t extra', 'select a from', 'select from t', 'select', '', 'from t', 'select a b c from t', 'select a, from t', 'select a,, b from t',
    'select a from t where', 'select a from t where a =', 'select a from t where and', 'select a from t where a = 1 and', 'select (a from t', 'select a) from t', 'select a from t where a in (1,', 'select a from t where a in 1',
    'select a from t where a between 1', 'select a from t where a between 1 or 2', 'select a from t where a is', 'select a from t where a is 1', 'select a from t where a not', 'select a from t where a not 1',
    'select f( from t', 'select f(a, from t', 'select f(distinct) from t', 'select f(distinct a, b) from t', 'select f(*) from t', 'select f(a.*) from t', 'select 1f() from t', 'select a.b.c.d from t', 'select a. from t', 'select .a from t',
    'select a from t group', 'select a from t group by', 'select a from t order', 'select a from t order by', 'select a from t order by a,', 'select a from s.t.u', 'select a from t as', 'select a from t join',
    'select a from t union select b from u', 'select a from t for update', 'select * , a from t', 'select t.* as x from t', 'select a as from t', 'select a as 1 from t', 'select a as \'x\' from t',
    'insert into t values (1)', 'update t set a = 1', 'delete from t', '(select 1)', 'select (((((1)))))', 'select a from t where ((a = 1))', 'SELECT A FROM T WHERE A = 1 ORDER BY A LIMIT 1',
    "select /* c */ a -- d\nfrom t", "select\n\ta\nfrom\n\tt", 'select a from t where a = 1 and b in (select c from u where d between 1 and 2 and exists (select 1 from v where e like \'x\'))',
    'select a from t where a @> b', 'select a from t where a ~ \'x\'', 'select a->b, a->>\'c\' from t', 'select a from t where a = any(b)', 'select interval \'1 day\'', 'select date \'2020-01-01\'', 'select a[1] from t',
    'select not not a from t', 'select a from t where not a in (1)', 'select a from t where a = -1', 'select a from t where a = - 1', 'select 1 + -1, 1 - -1, 1 -1, 1-1', 'select a * -b from t',
    'select current_timestamp, current_date from t', 'select a from t where a = \'x\' collate utf8mb4_bin', 'select distinct on (a) a, b from t', 'select top 5 a from t', 'select a from t limit all',
    'select extract(year from a) from t', 'select substring(a from 1 for 2) from t', 'select trim(both \'x\' from a) from t', 'select position(\'a\' in b) from t', 'select if(a, b, c) from t', 'select values(a) from t',
];
$tokenizerFor = ['base' => $tokenizers['base'], 'postgres' => $tokenizers['postgres']];
foreach ($selects as $sql) {
    foreach ($tokenizerFor as $dialect => $tokenizer) {
        $read = null;
        $parsed = outcome(function () use ($tokenizer, $parser, $sql, &$read) {
            return $read = $parser->parse(Tokenizer\Tokenizer::filter($tokenizer->tokenize($sql)));
        });
        emit('parse ' . $dialect . ' ' . $sql, [$parsed, $read === null ? null : serialized($serializers, $read)]);
        if ($read === null) {
            continue;
        }
        // What was written parses again, to a statement that writes the same.
        emit('reparse ' . $dialect . ' ' . $sql, outcome(function () use ($tokenizerFor, $serializers, $parser, $read): array {
            $again = [];
            foreach (['base' => 'base', 'postgres' => 'postgres'] as $tokenizer => $serializer) {
                $written = $serializers[$serializer]->serialize($read);
                $again[] = $serializers[$serializer]->serialize($parser->parse(Tokenizer\Tokenizer::filter($tokenizerFor[$tokenizer]->tokenize($written)))) === $written;
            }

            return $again;
        }));
    }
}

// Every statement cut short after each of its tokens, with a token dropped, and with two swapped: the parser's errors.
foreach ($selects as $sql) {
    $all = outcome(fn () => Tokenizer\Tokenizer::filter($tokenizers['base']->tokenize($sql)));
    if (! isset($all['value'])) {
        continue;
    }
    $all = Tokenizer\Tokenizer::filter($tokenizers['base']->tokenize($sql));
    $eof = $all[count($all) - 1];
    $results = [];
    for ($cut = 0; $cut < count($all) - 1; $cut++) {
        $results['cut ' . $cut] = outcome(fn () => $parser->parse([...array_slice($all, 0, $cut), $eof]));
        $results['drop ' . $cut] = outcome(fn () => $parser->parse([...array_slice($all, 0, $cut), ...array_slice($all, $cut + 1)]));
        if ($cut > 0) {
            $swapped = $all;
            [$swapped[$cut - 1], $swapped[$cut]] = [$swapped[$cut], $swapped[$cut - 1]];
            $results['swap ' . $cut] = outcome(fn () => $parser->parse($swapped));
        }
    }
    emit('broken ' . $sql, $results);
}

$tokens = Tokenizer\Tokenizer::filter($tokenizers['base']->tokenize('select a from t where a = 1'));
$unfiltered = $tokenizers['base']->tokenize("select a /* c */ from t -- d\n");
emit('parser misuse', [
    'nothing' => outcome(fn () => $parser->parse([])),
    'only eof' => outcome(fn () => $parser->parse([$tokens[count($tokens) - 1]])),
    'no eof' => outcome(fn () => $parser->parse(array_slice($tokens, 0, -1))),
    'no eof, cut' => outcome(fn () => $parser->parse(array_slice($tokens, 0, 3))),
    'one keyword' => outcome(fn () => $parser->parse([$tokens[0]])),
    'unfiltered' => outcome(fn () => $parser->parse($unfiltered)),
    'a string' => outcome(fn () => $parser->parse(['select', '1'])),
    'a null' => outcome(fn () => $parser->parse([null])),
    'an object' => outcome(fn () => $parser->parse([new stdClass()])),
    'a token then a string' => outcome(fn () => $parser->parse([$tokens[0], 'x'])),
    'keyed' => outcome(fn () => $parser->parse(array_combine(array_map(fn (int $i): string => 'k' . $i, array_keys($tokens)), $tokens))),
    'gap' => outcome(fn () => $parser->parse([0 => $tokens[0], 2 => $tokens[1], 3 => $tokens[2]])),
    'reversed keys' => outcome(fn () => $parser->parse(array_reverse($tokens, true))),
    'not an array' => outcome(fn () => $parser->parse('select 1')),
    'no argument' => outcome(fn () => $parser->parse()),
    'twice' => outcome(fn () => [$parser->parse($tokens) == $parser->parse($tokens), $parser->parse($tokens) === $parser->parse($tokens)]),
    'after a failure' => outcome(function () use ($parser, $tokens) {
        try {
            $parser->parse(array_slice($tokens, 0, 2));
        } catch (Throwable) {
        }

        return $parser->parse($tokens);
    }),
    'made by hand' => outcome(fn () => $parser->parse([
        new Token(TokenType::Keyword, 'select', 0), new Token(TokenType::Identifier, 'a', 7), new Token(TokenType::Keyword, 'FROM', 9),
        new Token(TokenType::QuotedIdentifier, '`t`', 14), new Token(TokenType::Eof, '', 17),
    ])),
    'odd tokens' => array_map(fn (array $made): array => outcome(fn () => $parser->parse([new Token(TokenType::Keyword, 'SELECT', 0), new Token(...$made), new Token(TokenType::Eof, '', 9)])), [
        [TokenType::Integer, 'abc', 7], [TokenType::Integer, '', 7], [TokenType::Float, 'x', 7], [TokenType::String, 'abc', 7], [TokenType::String, "'", 7], [TokenType::String, '', 7],
        [TokenType::QuotedIdentifier, 'a', 7], [TokenType::QuotedIdentifier, '', 7], [TokenType::QuotedIdentifier, '`', 7], [TokenType::Identifier, '', 7], [TokenType::Identifier, 'a b', 7],
        [TokenType::Keyword, 'nope', 7], [TokenType::Keyword, '', 7], [TokenType::Boolean, 'yes', 7], [TokenType::Null, 'x', 7], [TokenType::Placeholder, '', 7], [TokenType::Operator, '??', 7],
        [TokenType::Star, 'x', 7], [TokenType::Whitespace, ' ', 7], [TokenType::Eof, 'x', 7], [TokenType::Identifier, "\xff", 7], [TokenType::String, "'\xff'", 7], [TokenType::Identifier, 'a', -5],
        [TokenType::Integer, '12', PHP_INT_MAX], [TokenType::Identifier, 'é', 7], [TokenType::String, "'é\xff'", 7],
    ]),
    'deep' => array_map(fn (int $depth): array => outcome(function () use ($parser, $tokenizers, $serializers, $depth) {
        $sql = 'select ' . str_repeat('(', $depth) . '1' . str_repeat(')', $depth);

        return strlen($serializers['base']->serialize($parser->parse(Tokenizer\Tokenizer::filter($tokenizers['base']->tokenize($sql)))));
    }), [1, 100, 250, 255, 256, 257, 300, 5000]),
    'deep subqueries' => array_map(fn (int $depth): array => outcome(function () use ($parser, $tokenizers, $serializers, $depth) {
        $sql = str_repeat('select (', $depth) . 'select 1' . str_repeat(')', $depth);

        return strlen($serializers['base']->serialize($parser->parse(Tokenizer\Tokenizer::filter($tokenizers['base']->tokenize($sql)))));
    }), [1, 50, 120, 127, 128, 129, 255, 256, 257, 1000]),
    'deep nots' => array_map(fn (int $depth): array => outcome(function () use ($parser, $tokenizers, $serializers, $depth) {
        $sql = 'select ' . str_repeat('not ', $depth) . 'a from t where ' . str_repeat('- ', $depth) . '1';

        return strlen($serializers['base']->serialize($parser->parse(Tokenizer\Tokenizer::filter($tokenizers['base']->tokenize($sql)))));
    }), [1, 200, 255, 256, 257, 1000]),
]);

// Nodes made by hand: every node, with the values a parser never produces.
$one = new AST\Literal(1);
$a = new AST\Reference\Column('a');
$specification = new AST\Specification\Window([$a], [new AST\OrderByItem($a, OrderDirection::Desc, NullsPosition::Last)], 'ROWS', 'UNBOUNDED PRECEDING', 'CURRENT ROW');
$inner = new AST\Statement\Select([$one]);
final class CorpusExpression implements AST\Expression
{
}
$custom = new CorpusExpression();
$expressions = [
    'literal null' => new AST\Literal(null), 'literal true' => new AST\Literal(true), 'literal false' => new AST\Literal(false), 'literal 0' => new AST\Literal(0),
    'literal -5' => new AST\Literal(-5), 'literal max' => new AST\Literal(PHP_INT_MAX), 'literal min' => new AST\Literal(PHP_INT_MIN), 'literal 1.5' => new AST\Literal(1.5),
    'literal 1.0' => new AST\Literal(1.0), 'literal -0.0' => new AST\Literal(-0.0), 'literal 1e100' => new AST\Literal(1e100), 'literal 1e-10' => new AST\Literal(1e-10),
    'literal 0.1+0.2' => new AST\Literal(0.1 + 0.2), 'literal inf' => new AST\Literal(INF), 'literal -inf' => new AST\Literal(-INF), 'literal nan' => new AST\Literal(NAN),
    'literal 1e15' => new AST\Literal(1e15), 'literal 123456789012345678' => new AST\Literal(123456789012345678.0), 'literal empty' => new AST\Literal(''),
    'literal text' => new AST\Literal('abc'), 'literal quote' => new AST\Literal("it's"), 'literal backslash' => new AST\Literal('a\\b'), 'literal both' => new AST\Literal("\\'"),
    'literal double quote' => new AST\Literal('a"b`c'), 'literal newline' => new AST\Literal("a\nb\0c"), 'literal utf8' => new AST\Literal('é😀'), 'literal bytes' => new AST\Literal("\xff\xfe'\xc3"),
    'literal numeric string' => new AST\Literal('12'),
    'placeholder' => new AST\Placeholder('?'), 'placeholder named' => new AST\Placeholder(':a'), 'placeholder empty' => new AST\Placeholder(''), 'placeholder odd' => new AST\Placeholder("x'; drop \xff"),
    'raw' => new AST\Raw('NOW()'), 'raw empty' => new AST\Raw(''), 'raw bytes' => new AST\Raw("\xff é"),
    'star' => new AST\Star(), 'star table' => new AST\Star('t'), 'star schema' => new AST\Star('t', 's'), 'star schema only' => new AST\Star(null, 's'), 'star quoted' => new AST\Star('a`b"c'), 'star empty' => new AST\Star(''),
    'column' => $a, 'column table' => new AST\Reference\Column('a', 't'), 'column schema' => new AST\Reference\Column('a', 't', 's'), 'column schema only' => new AST\Reference\Column('a', null, 's'),
    'column quotes' => new AST\Reference\Column('a`b', 'c"d', "e'f"), 'column empty' => new AST\Reference\Column(''), 'column bytes' => new AST\Reference\Column("\xff`é\""), 'column spaces' => new AST\Reference\Column('a b.c'),
    'aliased' => new AST\Expression\Aliased($a, 'x'), 'aliased quotes' => new AST\Expression\Aliased($a, 'x`"y'), 'aliased empty' => new AST\Expression\Aliased($a, ''), 'aliased aliased' => new AST\Expression\Aliased(new AST\Expression\Aliased($a, 'x'), 'y'),
    'between' => new AST\Expression\Between($a, $one, new AST\Literal(2)), 'not between' => new AST\Expression\Between($a, $one, new AST\Literal(2), true),
    'between binaries' => new AST\Expression\Between(new AST\Expression\Binary($a, 'OR', $a), new AST\Expression\Binary($a, 'AND', $a), new AST\Expression\Binary($a, '+', $a)),
    'cast' => new AST\Expression\Cast($a, 'INT'), 'cast arguments' => new AST\Expression\Cast($a, 'decimal(10, 2)'), 'cast words' => new AST\Expression\Cast(new AST\Expression\Binary($a, '+', $one), 'double precision'),
    'exists' => new AST\Expression\Exists($inner), 'not exists' => new AST\Expression\Exists($inner, true), 'subquery' => new AST\Expression\Subquery($inner),
    'in' => new AST\Expression\In($a, [$one, new AST\Literal('x')]), 'not in' => new AST\Expression\In($a, [$one], true), 'in nothing' => new AST\Expression\In($a, []), 'in select' => new AST\Expression\In($a, $inner),
    'not in select' => new AST\Expression\In($a, $inner, true), 'in keyed' => new AST\Expression\In($a, ['x' => $one, 9 => $a]), 'in binary' => new AST\Expression\In(new AST\Expression\Binary($a, 'OR', $a), [new AST\Expression\Binary($a, 'OR', $a)]),
    'in a string' => new AST\Expression\In($a, ['x']), 'in a null' => new AST\Expression\In($a, [null]), 'in a select element' => new AST\Expression\In($a, [$inner]), 'in a table' => new AST\Expression\In($a, [new AST\Reference\Table('t')]),
    'func' => new AST\Call\Func('NOW'), 'func arguments' => new AST\Call\Func('COALESCE', [$a, $one]), 'func distinct' => new AST\Call\Func('COUNT', [$a], true), 'func distinct nothing' => new AST\Call\Func('COUNT', [], true),
    'func filter' => new AST\Call\Func('COUNT', [new AST\Star()], false, new AST\Expression\Binary($a, '>', $one)), 'func lower' => new AST\Call\Func('lower_Case1', [$a]), 'func keyed' => new AST\Call\Func('F', ['k' => $a]),
    'func bad argument' => new AST\Call\Func('F', [1]), 'func case when argument' => new AST\Call\Func('F', [new AST\Expression\CaseWhen($a, $one)]),
    'unary' => new AST\Expression\Unary('-', $a), 'unary not' => new AST\Expression\Unary('NOT', $a), 'unary lower not' => new AST\Expression\Unary('not', $a), 'unary postfix' => new AST\Expression\Unary('IS NULL', $a, false),
    'unary postfix not null' => new AST\Expression\Unary('IS NOT NULL', $a, false), 'unary odd' => new AST\Expression\Unary('~', $a), 'unary empty' => new AST\Expression\Unary('', $a), 'unary word' => new AST\Expression\Unary('EXISTS', $a),
    'unary minus minus' => new AST\Expression\Unary('-', new AST\Expression\Unary('-', $a)), 'unary minus literal' => new AST\Expression\Unary('-', new AST\Literal(-1)), 'unary of binary' => new AST\Expression\Unary('-', new AST\Expression\Binary($a, '+', $one)),
    'unary not of binary' => new AST\Expression\Unary('NOT', new AST\Expression\Binary($a, 'AND', $one)), 'unary postfix of binary' => new AST\Expression\Unary('IS NULL', new AST\Expression\Binary($a, 'OR', $one), false),
    'unary plus' => new AST\Expression\Unary('+', $a), 'unary prefix spaced' => new AST\Expression\Unary(' x ', $a),
    'window' => new AST\Expression\Window(new AST\Call\Func('ROW_NUMBER')), 'window named' => new AST\Expression\Window(new AST\Call\Func('SUM', [$a]), 'w'), 'window named quotes' => new AST\Expression\Window(new AST\Call\Func('SUM', [$a]), 'w`"'),
    'window specification' => new AST\Expression\Window(new AST\Call\Func('SUM', [$a]), null, $specification), 'window both' => new AST\Expression\Window(new AST\Call\Func('SUM', [$a]), 'w', $specification),
    'window empty specification' => new AST\Expression\Window($a, null, new AST\Specification\Window()), 'window empty name' => new AST\Expression\Window($a, ''),
    'window frames' => array_map(fn (array $frame): AST\Expression\Window => new AST\Expression\Window($a, null, new AST\Specification\Window([], [], ...$frame)), [
        ['ROWS', 'UNBOUNDED PRECEDING', null], ['ROWS', null, 'CURRENT ROW'], ['RANGE', '1 PRECEDING', '1 FOLLOWING'], [null, 'a', 'b'], ['ROWS', null, null], ['', '', ''], ['groups', 'x', null], [null, null, 'b'],
    ]),
    'window partition only' => new AST\Expression\Window($a, null, new AST\Specification\Window([$a, $one])), 'window order only' => new AST\Expression\Window($a, null, new AST\Specification\Window([], [new AST\OrderByItem($a)])),
    'window bad partition' => new AST\Expression\Window($a, null, new AST\Specification\Window(['a'])), 'window bad order' => new AST\Expression\Window($a, null, new AST\Specification\Window([], [$a])),
    'conditional' => new AST\Expression\Conditional(null, [new AST\Expression\CaseWhen($a, $one)]), 'conditional operand' => new AST\Expression\Conditional($a, [new AST\Expression\CaseWhen($one, $one), new AST\Expression\CaseWhen($a, $a)], new AST\Literal('z')),
    'conditional else' => new AST\Expression\Conditional(null, [new AST\Expression\CaseWhen($a, $one)], $a), 'conditional nothing' => new AST\Expression\Conditional(null, []), 'conditional only else' => new AST\Expression\Conditional($a, [], $one),
    'conditional bad when' => new AST\Expression\Conditional(null, [$a]), 'conditional string when' => new AST\Expression\Conditional(null, ['x']), 'conditional keyed' => new AST\Expression\Conditional(null, ['k' => new AST\Expression\CaseWhen($a, $one)]),
    'conditional binaries' => new AST\Expression\Conditional(new AST\Expression\Binary($a, 'OR', $a), [new AST\Expression\CaseWhen(new AST\Expression\Binary($a, 'OR', $a), new AST\Expression\Binary($a, 'OR', $a))], new AST\Expression\Binary($a, 'OR', $a)),
    'case when alone' => new AST\Expression\CaseWhen($a, $one), 'custom expression' => $custom, 'aliased custom' => new AST\Expression\Aliased($custom, 'x'), 'binary custom' => new AST\Expression\Binary($a, '=', $custom),
    'subclass of a node' => new readonly class ('a', 't') extends AST\Reference\Column {
    },
    'subclass with a method' => new readonly class (5) extends AST\Literal {
        public function extra(): string
        {
            return 'x';
        }
    },
];
$operators = ['OR', 'AND', '=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'ILIKE', 'NOT LIKE', 'NOT ILIKE', '+', '-', '||', '*', '/', '%', 'or', 'And', 'like', 'IS', 'IN', '@>', '', ' ', 'XOR', '->', '::', 'not like'];
foreach ($operators as $outer) {
    foreach ($operators as $nested) {
        $expressions['binary ' . $outer . ' over ' . $nested] = [
            new AST\Expression\Binary(new AST\Expression\Binary($a, $nested, $one), $outer, $a),
            new AST\Expression\Binary($a, $outer, new AST\Expression\Binary($a, $nested, $one)),
            new AST\Expression\Binary(new AST\Expression\Binary($a, $nested, $one), $outer, new AST\Expression\Binary($one, $nested, $a)),
        ];
    }
    $expressions['binary ' . $outer . ' over others'] = [
        new AST\Expression\Binary(new AST\Expression\Unary('-', $a), $outer, new AST\Expression\Unary('NOT', $a)),
        new AST\Expression\Binary(new AST\Expression\Between($a, $one, $one), $outer, new AST\Expression\In($a, [$one])),
        new AST\Expression\Binary(new AST\Expression\Aliased($a, 'x'), $outer, new AST\Expression\Subquery($inner)),
        new AST\Expression\Binary(new AST\Expression\Conditional(null, [new AST\Expression\CaseWhen($a, $one)]), $outer, new AST\Expression\Cast($a, 'INT')),
        new AST\Expression\Binary(new AST\Expression\Binary(new AST\Expression\Binary($a, $outer, $a), $outer, $a), $outer, new AST\Expression\Binary($a, $outer, new AST\Expression\Binary($a, $outer, $a))),
    ];
}
foreach ([-1, 14, 17, 5] as $precision) {
    ini_set('precision', (string) $precision);
    emit('floats at precision ' . $precision, array_map(
        fn (float $float): array => [outcome(fn () => $serializers['base']->serializeExpression(new AST\Literal($float))), outcome(fn () => $serializers['postgres']->serializeExpression(new AST\Expression\Binary($a, 'AND', new AST\Literal($float))))],
        [0.0, -0.0, 1.0, 1.5, -2.25, 0.1 + 0.2, 1e15, 1e16, 1e17, 1e-5, 1e-4, 123456789012345678.0, 1e100, PHP_FLOAT_MAX, PHP_FLOAT_MIN, PHP_FLOAT_EPSILON, 100.0, 0.000123456789, INF, -INF, NAN],
    ));
}
ini_restore('precision');
foreach ($expressions as $name => $expression) {
    emit('expression ' . $name, is_array($expression) ? array_map(fn (object $each): array => serialized($serializers, $each), $expression) : serialized($serializers, $expression));
}
emit('expression as a column', array_map(
    fn (object $expression): array => serialized($serializers, new AST\Statement\Select([$expression, $expression], new AST\Reference\Table('t'), [], $expression, [$expression], $expression, [new AST\OrderByItem($expression)], $expression, $expression)),
    array_filter($expressions, is_object(...)),
));

$table = new AST\Reference\Table('t');
$statements = [
    'empty' => new AST\Statement\Select(), 'columns' => new AST\Statement\Select([$a, $one]), 'no columns' => new AST\Statement\Select([], $table), 'distinct' => new AST\Statement\Select([$a], $table, distinct: true),
    'distinct nothing' => new AST\Statement\Select(distinct: true), 'table alias' => new AST\Statement\Select([$a], new AST\Reference\Table('t', 'u')), 'table schema' => new AST\Statement\Select([$a], new AST\Reference\Table('t', 'u', 's')),
    'table schema only' => new AST\Statement\Select([$a], new AST\Reference\Table('t', null, 's')), 'table quotes' => new AST\Statement\Select([$a], new AST\Reference\Table('t`"', 'u`"', 's`"')), 'table empty' => new AST\Statement\Select([$a], new AST\Reference\Table('', '', '')),
    'table bytes' => new AST\Statement\Select([$a], new AST\Reference\Table("\xff", "é\xfe")), 'subquery source' => new AST\Statement\Select([$a], new AST\SubquerySource($inner, 's')), 'subquery source empty alias' => new AST\Statement\Select([$a], new AST\SubquerySource($inner, '')),
    'subquery source quotes' => new AST\Statement\Select([$a], new AST\SubquerySource($inner, 's`"')), 'where' => new AST\Statement\Select([$a], $table, where: new AST\Expression\Binary($a, '=', $one)), 'where without from' => new AST\Statement\Select([$a], where: $one),
    'group' => new AST\Statement\Select([$a], $table, groupBy: [$a, $one]), 'having' => new AST\Statement\Select([$a], $table, having: $one), 'group having' => new AST\Statement\Select([$a], $table, groupBy: [$a], having: new AST\Expression\Binary($a, '>', $one)),
    'limit' => new AST\Statement\Select([$a], $table, limit: new AST\Literal(10)), 'offset' => new AST\Statement\Select([$a], $table, offset: new AST\Literal(5)), 'limit offset' => new AST\Statement\Select([$a], $table, limit: new AST\Placeholder('?'), offset: new AST\Placeholder('?')),
    'limit expression' => new AST\Statement\Select([$a], $table, limit: new AST\Expression\Binary($a, '+', $one), offset: new AST\Expression\Subquery($inner)), 'limit text' => new AST\Statement\Select([$a], $table, limit: new AST\Literal('x')),
    'orders' => new AST\Statement\Select([$a], $table, orderBy: array_merge(...array_map(fn (OrderDirection $direction): array => [new AST\OrderByItem($a, $direction), new AST\OrderByItem($a, $direction, NullsPosition::First), new AST\OrderByItem($a, $direction, NullsPosition::Last)], OrderDirection::cases()))),
    'order by expression' => new AST\Statement\Select([$a], $table, orderBy: [new AST\OrderByItem(new AST\Expression\Binary($a, '+', $one)), new AST\OrderByItem(new AST\Call\Func('RAND'))]),
    'joins' => new AST\Statement\Select([$a], $table, array_map(fn (string $type): AST\JoinClause => new AST\JoinClause($type, new AST\Reference\Table('u', 'v'), new AST\Expression\Binary($a, '=', $one)), ['JOIN', 'INNER JOIN', 'LEFT JOIN', 'LEFT OUTER JOIN', 'RIGHT JOIN', 'FULL OUTER JOIN', 'CROSS JOIN', 'NATURAL JOIN', 'join', '', 'x', ','])),
    'joins without condition' => new AST\Statement\Select([$a], $table, array_map(fn (string $type): AST\JoinClause => new AST\JoinClause($type, new AST\Reference\Table('u')), ['JOIN', 'CROSS JOIN', 'NATURAL JOIN', 'LEFT JOIN', '', ','])),
    'join subquery' => new AST\Statement\Select([$a], $table, [new AST\JoinClause('JOIN', new AST\SubquerySource($inner, 's'), $one), new AST\JoinClause('LEFT JOIN', new AST\SubquerySource($inner, 's'))]),
    'joins without from' => new AST\Statement\Select([$a], null, [new AST\JoinClause('JOIN', $table, $one)]),
    'cte' => new AST\Statement\Select([$a], $table, ctes: [new AST\Definition\Cte('c', $inner)]), 'cte columns' => new AST\Statement\Select([$a], $table, ctes: [new AST\Definition\Cte('c', $inner, ['x', 'y`"'])]),
    'cte recursive' => new AST\Statement\Select([$a], $table, ctes: [new AST\Definition\Cte('c', $inner, [], true)]), 'cte second recursive' => new AST\Statement\Select([$a], $table, ctes: [new AST\Definition\Cte('c', $inner), new AST\Definition\Cte('d', $inner, ['x'], true)]),
    'cte many' => new AST\Statement\Select([$a], $table, ctes: [new AST\Definition\Cte('c', $inner), new AST\Definition\Cte('d`"', $inner), new AST\Definition\Cte('', $inner)]),
    'cte nested' => new AST\Statement\Select([$a], $table, ctes: [new AST\Definition\Cte('c', new AST\Statement\Select([$a], ctes: [new AST\Definition\Cte('d', $inner, ['x'], true)]))]),
    'cte keyed columns' => new AST\Statement\Select([$a], ctes: [new AST\Definition\Cte('c', $inner, ['k' => 'x', 5 => 'y'])]), 'cte integer column' => new AST\Statement\Select([$a], ctes: [new AST\Definition\Cte('c', $inner, [1])]),
    'cte null column' => new AST\Statement\Select([$a], ctes: [new AST\Definition\Cte('c', $inner, [null])]), 'cte array column' => new AST\Statement\Select([$a], ctes: [new AST\Definition\Cte('c', $inner, [[]])]),
    'cte float column' => new AST\Statement\Select([$a], ctes: [new AST\Definition\Cte('c', $inner, [1.5])]), 'cte bool column' => new AST\Statement\Select([$a], ctes: [new AST\Definition\Cte('c', $inner, [true])]),
    'windows' => new AST\Statement\Select([$a], $table, windows: [new AST\Definition\Window('w', $specification), new AST\Definition\Window('v`"', new AST\Specification\Window())]),
    'everything' => new AST\Statement\Select(
        [new AST\Expression\Aliased($a, 'x'), new AST\Star('t')],
        new AST\Reference\Table('t', 'u', 's'),
        [new AST\JoinClause('LEFT JOIN', new AST\Reference\Table('v'), new AST\Expression\Binary($a, '=', $one))],
        new AST\Expression\Binary($a, 'AND', new AST\Expression\Binary($a, 'OR', $one)),
        [$a],
        new AST\Expression\Binary(new AST\Call\Func('COUNT', [new AST\Star()]), '>', $one),
        [new AST\OrderByItem($a, OrderDirection::Desc, NullsPosition::Last)],
        new AST\Literal(10),
        new AST\Literal(5),
        true,
        [new AST\Definition\Cte('c', $inner, ['x'], true)],
        [new AST\Definition\Window('w', $specification)],
    ),
    'bad column' => new AST\Statement\Select(['a']), 'null column' => new AST\Statement\Select([null]), 'table as a column' => new AST\Statement\Select([$table]), 'select as a column' => new AST\Statement\Select([$inner]),
    'bad join' => new AST\Statement\Select([$a], $table, [$a]), 'string join' => new AST\Statement\Select([$a], $table, ['JOIN']), 'bad group' => new AST\Statement\Select([$a], $table, groupBy: ['a']),
    'bad order' => new AST\Statement\Select([$a], $table, orderBy: [$a]), 'string order' => new AST\Statement\Select([$a], $table, orderBy: ['a']), 'bad cte' => new AST\Statement\Select([$a], $table, ctes: [$inner]),
    'bad window' => new AST\Statement\Select([$a], $table, windows: [$specification]), 'string window' => new AST\Statement\Select([$a], $table, windows: ['w']),
    'bad later' => new AST\Statement\Select([$a, 'x'], $table, [$a], windows: ['w']), 'keyed columns' => new AST\Statement\Select(['x' => $a, 3 => $one]),
    'subclass' => new readonly class ([$a], $table) extends AST\Statement\Select {
    },
    'with' => (new AST\Statement\Select([$a], $table))->with(where: $one, limit: new AST\Literal(1), distinct: true),
];
foreach ($statements as $name => $statement) {
    emit('statement ' . $name, serialized($serializers, $statement));
}

// Trees deeper and chains longer than a coroutine's stack would take if the extension recursed on it.
$nest = function (int $depth, callable $wrap) use ($one): AST\Expression {
    $expression = $one;
    for ($i = 0; $i < $depth; $i++) {
        $expression = $wrap($expression);
    }

    return $expression;
};
emit('deep by hand', array_map(fn (int $depth): array => [
    outcome(fn () => md5($serializers['base']->serializeExpression($nest($depth, fn (AST\Expression $e): AST\Expression => new AST\Expression\Unary('-', $e))))),
    outcome(fn () => md5($serializers['postgres']->serializeExpression($nest($depth, fn (AST\Expression $e): AST\Expression => new AST\Expression\Binary($e, '+', $one))))),
    outcome(fn () => md5($serializers['postgres']->serializeExpression($nest($depth, fn (AST\Expression $e): AST\Expression => new AST\Expression\Binary($one, 'OR', $e))))),
    outcome(fn () => md5($serializers['base']->serializeExpression($nest($depth, fn (AST\Expression $e): AST\Expression => new AST\Expression\Subquery(new AST\Statement\Select([$e])))))),
    outcome(fn () => md5($serializers['base']->serialize(new AST\Statement\Select([$nest($depth, fn (AST\Expression $e): AST\Expression => new AST\Call\Func('F', [$one, $e]))], where: $nest($depth, fn (AST\Expression $e): AST\Expression => new AST\Expression\In($one, [$e])))))),
    outcome(fn () => $serializers['base']->serializeExpression($nest($depth, fn (AST\Expression $e): AST\Expression => new AST\Expression\Binary($e, '+', new AST\Expression\CaseWhen($one, $one))))),
    outcome(fn () => $serializers['base']->serializeExpression($nest($depth, fn (AST\Expression $e): AST\Expression => $e instanceof AST\Literal ? new AST\Call\Func('F', ['x']) : new AST\Expression\Unary('-', $e)))),
], [1, 10, 63, 64, 65, 300, 3000, 20000]));
emit('long chains', array_map(fn (int $terms): array => array_map(fn (string $sql): array => outcome(function () use ($parser, $tokenizers, $serializers, $sql) {
    $read = $parser->parse(Tokenizer\Tokenizer::filter($tokenizers['base']->tokenize($sql)));

    return [md5($serializers['base']->serialize($read)), md5(serialize(exported($read->where ?? $read->columns[0])))];
}), [
    'select 1' . str_repeat(' + 1', $terms), 'select a from t where a = 1' . str_repeat(' or a = 1', $terms), 'select a from t where a in (' . str_repeat('1, ', $terms) . '1)',
    'select f(' . str_repeat('a, ', $terms) . 'a)', 'select 1' . str_repeat(' + 1', $terms) . ' +', 'select a from t where a = 1' . str_repeat(' and (a = 1 or b = 2)', $terms) . ' and',
    'select ' . str_repeat('not ', min($terms, 1000)) . 'a', 'select ' . str_repeat('- ', min($terms, 1000)) . 'a from t where ' . str_repeat('not ', min($terms, 1000)) . 'a = ' . str_repeat('+', min($terms, 1000)) . '1',
]), [10, 70, 500, 8000]));

emit('serializer arguments', [
    outcome(fn () => $serializers['base']->serialize($a)), outcome(fn () => $serializers['base']->serialize(null)), outcome(fn () => $serializers['base']->serialize('select 1')),
    outcome(fn () => $serializers['base']->serialize(new stdClass())), outcome(fn () => $serializers['base']->serialize()), outcome(fn () => $serializers['base']->serializeExpression($inner)),
    outcome(fn () => $serializers['base']->serializeExpression(null)), outcome(fn () => $serializers['base']->serializeExpression('a')), outcome(fn () => $serializers['base']->serializeExpression($table)),
    outcome(fn () => $serializers['base']->serializeExpression(new AST\OrderByItem($a))), outcome(fn () => $serializers['base']->serializeExpression()), outcome(fn () => $serializers['postgres']->serialize([])),
    outcome(fn () => $serializers['base']->quoteIdentifier('a')), outcome(fn () => (new ReflectionMethod(AST\Serializer::class, 'quoteIdentifier'))->isProtected()),
    outcome(fn () => (string) (new ReflectionMethod(AST\Serializer::class, 'serialize'))->getParameters()[0]->getType()), outcome(fn () => (string) (new ReflectionMethod(AST\Serializer\PostgreSQL::class, 'serializeExpression'))->getParameters()[0]->getType()),
    outcome(fn () => (string) (new ReflectionMethod(AST\Parser::class, 'parse'))->getParameters()[0]->getType()),
]);
emit('serializer classes', outcome(fn () => [
    $serializers['mariadb'] instanceof AST\Serializer\MySQL, $serializers['mysql'] instanceof AST\Serializer, $serializers['postgres'] instanceof AST\Serializer\MySQL, $serializers['sqlite'] instanceof AST\Serializer\PostgreSQL,
    get_parent_class($serializers['clickhouse']), get_parent_class($serializers['sqlite']), get_parent_class($serializers['base']), get_parent_class($parser), $serializers['base'] == new AST\Serializer(), clone $parser == $parser,
]));
// A subclass reaches the protected quoteIdentifier() of each dialect, and may override the public methods.
emit('subclasses', outcome(function () use ($inner, $a): array {
    $quoted = [];
    foreach ([AST\Serializer::class, AST\Serializer\MySQL::class, AST\Serializer\MariaDB::class, AST\Serializer\PostgreSQL::class, AST\Serializer\SQLite::class, AST\Serializer\ClickHouse::class] as $class) {
        $own = eval('return new class () extends \\' . $class . ' { public function quoted(string $name): string { return $this->quoteIdentifier($name); } };');
        $quoted[] = array_map(fn (string $name): mixed => exported($own->quoted($name)), ['a', 'a`b', 'a"b', '', "\xff`\"", 'a``b', 'é']);
        $quoted[] = $own->serializeExpression($a);
    }
    $loud = new class () extends AST\Serializer\PostgreSQL {
        public function serialize(AST\Statement\Select $stmt): string
        {
            return strtoupper(parent::serialize($stmt)) . ';';
        }
    };
    $quoted[] = [$loud->serialize(new AST\Statement\Select([$a], new AST\Reference\Table('t'))), $loud->serializeExpression($a)];
    $counting = new class () extends AST\Parser {
        public int $calls = 0;

        public function parse(array $tokens): AST\Statement\Select
        {
            $this->calls++;

            return parent::parse($tokens);
        }
    };
    $quoted[] = [$counting->parse([new Token(TokenType::Keyword, 'SELECT', 0), new Token(TokenType::Integer, '1', 7), new Token(TokenType::Eof, '', 8)])::class, $counting->calls];

    return $quoted;
}));

// The walker and the visitors stay PHP: they take what the parser made and hand the serializers what they changed.
emit('walked', outcome(function () use ($parser, $tokenizers, $serializers): array {
    $read = $parser->parse(Tokenizer\Tokenizer::filter($tokenizers['base']->tokenize('select a, u.b from t join u on t.id = u.id where a in (select c from t) order by a')));
    $walker = new AST\Walker();
    $renamed = $walker->walk($read, new AST\Visitor\TableRenamer(['t' => 'renamed', 'u' => 'other']));
    $filtered = $walker->walk($read, new AST\Visitor\FilterInjector(new AST\Expression\Binary(new AST\Reference\Column('tenant'), '=', new AST\Literal(7))));

    return [
        $serializers['base']->serialize($renamed), $serializers['postgres']->serialize($filtered), $serializers['base']->serialize($read),
        outcome(fn () => $walker->walk($read, new AST\Visitor\ColumnValidator(['a', 'b', 'c', 'id']))::class), outcome(fn () => $walker->walk($read, new AST\Visitor\ColumnValidator(['a']))),
    ];
}));

// --- Schema --------------------------------------------------------------------------------

$schemas = [
    'mysql' => new Schema\MySQL(), 'postgres' => new Schema\PostgreSQL(), 'sqlite' => new Schema\SQLite(),
    'clickhouse' => new Schema\ClickHouse(), 'mongo' => new Schema\MongoDB(),
];

/** A table whose properties can be set to anything, past what the fluent methods validate. */
class CorpusTable extends Schema\Table
{
    public function put(string $property, mixed $value): static
    {
        $this->$property = $value;

        return $this;
    }
}

class CorpusClickHouseTable extends Schema\Table\ClickHouse
{
    public function put(string $property, mixed $value): static
    {
        $this->$property = $value;

        return $this;
    }
}

/** What the table $fill fills compiles to, as CREATE and as ALTER. */
function blueprinted(Schema $schema, callable $fill, string $name = 't'): array
{
    $table = $schema->table($name);
    try {
        $fill($table);
    } catch (Throwable $e) {
        return ['fill throws' => $e::class, 'message' => exported($e->getMessage())];
    }

    return [outcome(fn () => $table->create()), outcome(fn () => $table->alter())];
}

$fills = [
    'nothing' => fn ($t) => null,
    'id' => fn ($t) => $t->id(),
    'id named' => fn ($t) => $t->id('uid'),
    'string' => fn ($t) => $t->string('s'),
    'string sized' => fn ($t) => $t->string('s', 10)->nullable()->default('x'),
    'text' => fn ($t) => $t->text('body')->nullable(),
    'medium text' => fn ($t) => $t->mediumText('body'),
    'long text' => fn ($t) => $t->longText('body'),
    'tiny integer' => fn ($t) => $t->tinyInteger('n')->unsigned()->default(0),
    'small integer' => fn ($t) => $t->smallInteger('n')->default(-1),
    'integer' => fn ($t) => $t->integer('n')->unsigned()->nullable(),
    'big integer' => fn ($t) => $t->bigInteger('n')->unsigned()->default(9007199254740993),
    'decimal' => fn ($t) => $t->decimal('price'),
    'decimal sized' => fn ($t) => $t->decimal('price', 12, 4)->default(0),
    'uuid' => fn ($t) => $t->uuid('ref')->unique(),
    'float' => fn ($t) => $t->float('ratio')->default(0.5),
    'boolean' => fn ($t) => $t->boolean('flag')->default(true),
    'boolean false' => fn ($t) => $t->boolean('flag')->default(false)->nullable(),
    'datetime' => fn ($t) => $t->datetime('at'),
    'datetime precise' => fn ($t) => $t->datetime('at', 6)->nullable()->defaultRaw('CURRENT_TIMESTAMP(6)'),
    'timestamp' => fn ($t) => $t->timestamp('at'),
    'timestamp precise' => fn ($t) => $t->timestamp('at', 3)->defaultRaw('now()'),
    'json' => fn ($t) => $t->json('data')->nullable(),
    'binary' => fn ($t) => $t->binary('blob'),
    'enum' => fn ($t) => $t->enum('status', ['draft', 'live', "it's", 'back\\slash', ''])->default('draft'),
    'enum empty' => fn ($t) => $t->enum('status', []),
    'point' => fn ($t) => $t->point('at'),
    'point srid' => fn ($t) => $t->point('at', 3857)->nullable(),
    'linestring' => fn ($t) => $t->linestring('path', 0),
    'polygon' => fn ($t) => $t->polygon('area'),
    'timestamps' => fn ($t) => $t->timestamps(),
    'timestamps precise' => fn ($t) => $t->timestamps(6),
    'serial' => fn ($t) => $t->serial('n'),
    'big serial' => fn ($t) => $t->bigSerial('n')->primary(),
    'small serial' => fn ($t) => $t->smallSerial('n'),
    'vector' => fn ($t) => $t->vector('embedding', 1536),
    'vector unsized' => fn ($t) => $t->vector('embedding'),
    'array' => fn ($t) => $t->array('tags', ColumnType::String),
    'array of arrays' => fn ($t) => $t->array('tags', ColumnType::Array),
    'tuple' => fn ($t) => $t->tuple('pair', [ColumnType::String, ColumnType::Integer, ColumnType::Float]),
    'fixed string' => fn ($t) => $t->fixedString('code', 8)->lowCardinality(),
    'low cardinality' => fn ($t) => $t->string('country')->lowCardinality()->codec('ZSTD(3)')->codec('Delta'),
    'column ttl' => fn ($t) => $t->datetime('at')->ttl('at + INTERVAL 1 DAY'),
    'primary' => fn ($t) => $t->string('key', 36)->primary(),
    'two primaries' => function ($t) {
        $t->integer('a')->primary();
        $t->integer('b')->primary();
    },
    'composite primary' => function ($t) {
        $t->integer('a');
        $t->integer('b');
        $t->primary(['a', 'b']);
    },
    'both primaries' => function ($t) {
        $t->integer('a')->primary();
        $t->primary(['a', 'b']);
    },
    'auto increment' => fn ($t) => $t->integer('n')->autoIncrement(),
    'auto increment primary' => fn ($t) => $t->integer('n')->autoIncrement()->primary()->default(5),
    'unique' => fn ($t) => $t->string('email')->unique()->nullable(),
    'after' => fn ($t) => $t->string('s')->after('other.col'),
    'comment' => fn ($t) => $t->string('s')->comment("it's a \\ comment"),
    'collation' => fn ($t) => $t->string('s')->collation('utf8mb4_bin'),
    'collation odd' => fn ($t) => $t->string('s')->collation('en-US"x'),
    'srid' => fn ($t) => $t->point('at')->srid(4326),
    'dimensions' => fn ($t) => $t->addColumn('v', ColumnType::Vector)->dimensions(3),
    'generated' => fn ($t) => $t->integer('total')->generatedAs('a + b'),
    'generated stored' => fn ($t) => $t->integer('total')->generatedAs('a + b')->stored()->nullable()->comment('sum'),
    'generated virtual' => fn ($t) => $t->integer('total')->generatedAs('a + b')->virtual(),
    'user type' => fn ($t) => $t->string('mood')->userType('public.mood'),
    'column check' => fn ($t) => $t->integer('age')->check('age >= 0'),
    'modify' => fn ($t) => $t->string('s', 64)->modify(),
    'modify column' => fn ($t) => $t->modifyColumn('s', ColumnType::Text)->nullable(),
    'default raw' => fn ($t) => $t->string('s')->defaultRaw('  (uuid())  ')->default('ignored'),
    'default null' => fn ($t) => $t->string('s')->nullable()->default(null),
    'raw column' => fn ($t) => $t->rawColumn('`x` INT GENERATED ALWAYS AS (1)')->rawIndex('KEY `k` (`x`)'),
    'index' => fn ($t) => $t->string('s')->index(['s']),
    'index named' => fn ($t) => $t->index(['a', 'b'], 'by_a_b'),
    'index options' => fn ($t) => $t->index(['a', 'b', 'c'], 'i', 'btree', 'text_pattern_ops', ['a' => 10, 'c' => 0], ['a' => 'desc', 'b' => 'ASC'], ['b' => 'utf8mb4_bin']),
    'index bad order' => fn ($t) => $t->index(['a'], 'i', orders: ['a' => 'sideways']),
    'index bad collation' => fn ($t) => $t->index(['a'], 'i', collations: ['a' => 'x y']),
    'index numeric columns' => fn ($t) => $t->index(['1', '2'], 'i', lengths: ['1' => 5], orders: ['2' => 'desc']),
    'unique index' => fn ($t) => $t->uniqueIndex(['email'], '', ['email' => 191], ['email' => 'desc']),
    'fulltext index' => fn ($t) => $t->fulltextIndex(['title', 'body']),
    'spatial index' => fn ($t) => $t->spatialIndex(['at'], 'geo'),
    'add index' => fn ($t) => $t->addIndex('i', ['a'], IndexType::Unique, ['a' => 3], ['a' => 'desc'], 'hash', 'ops', ['a' => 'C'], ['(lower(b))', 'c DESC']),
    'add index kinds' => function ($t) {
        foreach (IndexType::cases() as $type) {
            $t->addIndex('i_' . $type->value, ['a'], $type);
        }
    },
    'skip index' => fn ($t) => $t->index(['a'], 'i', algorithm: Schema\ClickHouse\IndexAlgorithm::cases()[0], granularity: 4),
    'skip indexes' => function ($t) {
        foreach (Schema\ClickHouse\IndexAlgorithm::cases() as $algorithm) {
            $t->index(['a'], 'i_' . $algorithm->value, algorithm: $algorithm, algorithmArgs: $algorithm->acceptsArgs() ? [0.01, 3, 'x'] : [], granularity: 2);
        }
    },
    'drop index' => fn ($t) => $t->dropIndex('i')->dropIndex('my.i'),
    'check' => fn ($t) => $t->check('positive', 'n > 0')->check('named.x', "s <> ''"),
    'rename column' => fn ($t) => $t->renameColumn('old', 'new')->dropColumn('gone')->dropColumn('a.b'),
    'foreign key' => fn ($t) => $t->foreignKey('team_id')->references('id')->on('teams'),
    'foreign key actions' => function ($t) {
        foreach (ForeignKeyAction::cases() as $delete) {
            foreach (ForeignKeyAction::cases() as $update) {
                $t->foreignKey('c')->references('id')->on('db.other')->onDelete($delete)->onUpdate($update);
            }
        }
    },
    'foreign key bare' => fn ($t) => $t->foreignKey('team_id'),
    'add foreign key' => fn ($t) => $t->addForeignKey('team_id')->references('id')->on('teams')->onDelete(ForeignKeyAction::Cascade),
    'drop foreign key' => fn ($t) => $t->dropForeignKey('fk_team')->dropForeignKey('fk.b'),
    'partition range' => fn ($t) => $t->integer('n')->table->partitionByRange('n'),
    'partition list' => function ($t) {
        $t->integer('n');
        $t->partitionByList('n');
    },
    'partition hash' => function ($t) {
        $t->integer('n');
        $t->partitionByHash('n', 8);
    },
    'partition hash uncounted' => function ($t) {
        $t->integer('n');
        $t->partitionByHash('n');
    },
    'engines' => function ($t) {
        $t->string('a');
        $t->engine(Schema\ClickHouse\Engine::ReplicatedMergeTree, '/clickhouse/{shard}', '{replica}')->orderBy(['a']);
    },
    'engine memory' => fn ($t) => $t->string('a')->table->engine(Schema\ClickHouse\Engine::Memory),
    'order by' => function ($t) {
        $t->string('a');
        $t->integer('b');
        $t->orderBy(['a', 'b'])->partitionBy('toYYYYMM(at)')->sampleBy('b')->ttl('at + INTERVAL 30 DAY');
    },
    'order by raw' => function ($t) {
        $t->string('a');
        $t->orderByRaw('(a, cityHash64(b))')->settings(['index_granularity' => 8192, 'allow_nullable_key' => true, 'ratio' => 0.5, 'name' => 'x']);
    },
    'settings bad' => fn ($t) => $t->settings(['bad name' => 1]),
    'everything' => function ($t) {
        $t->id();
        $t->string('name', 120)->nullable()->default("o'k")->comment('the name');
        $t->integer('team_id')->unsigned();
        $t->decimal('price', 8, 2)->default(1.5);
        $t->boolean('live')->default(true);
        $t->datetime('at', 3)->nullable();
        $t->json('data')->nullable();
        $t->timestamps();
        $t->index(['name'], 'by_name', lengths: ['name' => 50]);
        $t->uniqueIndex(['team_id', 'name']);
    },
];
foreach ($schemas as $dialect => $schema) {
    foreach ($fills as $name => $fill) {
        emit("schema $dialect fill $name", blueprinted($schema, $fill));
    }
}

// Every column type at every size, added and modified.
foreach ($schemas as $dialect => $schema) {
    foreach (ColumnType::cases() as $type) {
        emit("schema $dialect type {$type->value}", array_map(fn (?int $size): array => [
            blueprinted($schema, fn ($t) => $t->addColumn('c', $type, $size)),
            blueprinted($schema, fn ($t) => $t->modifyColumn('c', $type, $size)->nullable()),
        ], [null, 0, 1, 6, 255, -1]));
        emit("schema $dialect column {$type->value}", array_map(fn (array $arguments): array => outcome(function () use ($schema, $type, $arguments): array {
            $table = new CorpusTable($schema, 't');
            $table->put('columns', [new Schema\Column($table, 'c', $type, ...$arguments)]);

            return [outcome(fn () => $table->create()), outcome(fn () => $table->alter())];
        }), [[], [10], [null, 5], [null, 12, 4], [null, null, 3], [null, null, null, 4326], [null, null, null, null, 3], [null, null, null, null, null, true], [0, 0, 0, 0, 0, true], [-5, -5, -5, -5, -5]]));
    }
}

// Defaults: every kind of value, at each precision a float is written with.
$defaults = [
    'x', '', "o'k", 'back\\slash', "é", "\xe9", "\xff\xfe", "nul\0l", '0', '1.50', ' padded ', 0, 1, -5, PHP_INT_MAX, PHP_INT_MIN,
    1.5, 0.0, -0.0, 0.1 + 0.2, 1e100, 1.0e-10, 100000.0, 1e15, 1e21, 123456789.12345678, INF, -INF, NAN, true, false, null,
    [1, 2], [], new stdClass(), ColumnType::String,
    new class () {
        public function __toString(): string
        {
            return "it's text";
        }
    },
];
foreach ([-1, 14, 17, 5] as $precision) {
    ini_set('precision', (string) $precision);
    foreach ($schemas as $dialect => $schema) {
        emit("schema $dialect defaults at precision $precision", array_map(fn (mixed $default): array => [
            blueprinted($schema, fn ($t) => $t->string('s')->default($default)),
            blueprinted($schema, fn ($t) => $t->float('f')->nullable()->default($default)),
            blueprinted($schema, fn ($t) => $t->integer('n')->autoIncrement()->primary()->default($default)),
        ], $defaults));
    }
}
ini_restore('precision');

// Names and text, in every place a blueprint holds one.
$names = [
    'users', 'my_db.users', 'a.b.c', '', ' ', 'sp ace', 'a`b', 'a"b', "a'b", 'a\\b', 'x; DROP TABLE y', '*', 'é', "\xe9", "\xff\xfe",
    "nul\0l", '123', '0', 'UPPER', str_repeat('n', 300), '.', 'a.', '.a', 'a..b', "line\nbreak", '--', '/*c*/', '$', "\u{a0}pad\u{a0}", "\x85x\x85", 'ǅ',
];
foreach ($schemas as $dialect => $schema) {
    foreach ($names as $name) {
        // MongoDB's statements are JSON: see the known difference at the end.
        if ($dialect === 'mongo' && ! mb_check_encoding($name, 'UTF-8')) {
            continue;
        }
        emit("schema $dialect named " . bin2hex($name), [
            blueprinted($schema, fn ($t) => $t->string('s'), $name),
            blueprinted($schema, fn ($t) => $t->string($name)->comment($name)->default($name)),
            blueprinted($schema, fn ($t) => $t->string('s')->after($name)),
            blueprinted($schema, fn ($t) => $t->string('s')->collation($name)),
            blueprinted($schema, fn ($t) => $t->string('s')->defaultRaw($name)),
            blueprinted($schema, fn ($t) => $t->integer('n')->generatedAs($name)),
            blueprinted($schema, fn ($t) => $t->integer('n')->check($name)),
            blueprinted($schema, fn ($t) => $t->string('s')->userType($name)),
            blueprinted($schema, fn ($t) => $t->enum('e', [$name, $name])),
            blueprinted($schema, fn ($t) => $t->index([$name], $name)),
            blueprinted($schema, fn ($t) => $t->index([$name])),
            blueprinted($schema, fn ($t) => $t->index(['a'], 'i', $name, $name)),
            blueprinted($schema, fn ($t) => $t->index([$name], 'i', lengths: [$name => 3], orders: [$name => 'desc'], collations: [$name => 'C'])),
            blueprinted($schema, fn ($t) => $t->index(['a'], 'i', orders: ['a' => $name])),
            blueprinted($schema, fn ($t) => $t->index(['a'], 'i', collations: ['a' => $name])),
            blueprinted($schema, fn ($t) => $t->addIndex($name, [$name], rawColumns: [$name])),
            blueprinted($schema, fn ($t) => $t->check($name, $name)),
            blueprinted($schema, fn ($t) => $t->renameColumn($name, $name)->dropColumn($name)->dropIndex($name)),
            blueprinted($schema, fn ($t) => $t->foreignKey($name)->references($name)->on($name)),
            blueprinted($schema, fn ($t) => $t->dropForeignKey($name)),
            blueprinted($schema, fn ($t) => $t->primary([$name, 'b'])),
            blueprinted($schema, fn ($t) => $t->rawColumn($name)->rawIndex($name)),
            blueprinted($schema, fn ($t) => $t->partitionByRange($name)),
            blueprinted($schema, fn ($t) => $t->partitionByHash($name, 4)),
            blueprinted($schema, fn ($t) => $t->string('a')->table->engine(Schema\ClickHouse\Engine::ReplacingMergeTree, $name)->orderBy([$name])),
            blueprinted($schema, fn ($t) => $t->string('a')->table->orderByRaw($name)->ttl($name)),
            blueprinted($schema, fn ($t) => $t->string('a')->table->partitionBy($name)->sampleBy($name)),
            blueprinted($schema, fn ($t) => $t->string('a')->table->settings([$name => $name])),
            blueprinted($schema, fn ($t) => $t->string('a')->table->settings(['k' => $name])),
            blueprinted($schema, fn ($t) => $t->string('a')->codec($name)->ttl($name)),
            outcome(fn () => $schema->table($name)->drop()),
            outcome(fn () => $schema->table($name)->dropIfExists()),
            outcome(fn () => $schema->table($name)->truncate()),
            outcome(fn () => $schema->table($name)->rename($name)),
            outcome(fn () => $schema->table('t')->rename($name)),
        ]);
    }
}

// A blueprint's properties set past the fluent methods: what a schema reads is not validated.
/** An object of $class with $properties set past what its constructor validates. */
function made(string $class, array $properties): object
{
    $reflection = new ReflectionClass($class);
    $object = $reflection->newInstanceWithoutConstructor();
    foreach ($properties as $property => $value) {
        if ($value !== 'unset') {
            $reflection->getProperty($property)->setValue($object, $value);
        }
    }

    return $object;
}

function indexed(array $properties): Schema\Index
{
    return made(Schema\Index::class, $properties + ['name' => 'i', 'columns' => ['a'], 'type' => IndexType::Index, 'lengths' => [], 'orders' => [], 'method' => '', 'operatorClass' => '', 'collations' => [], 'rawColumns' => [], 'algorithm' => null, 'algorithmArgs' => [], 'granularity' => null]);
}

$puts = [
    'columns not columns' => ['columns', ['x', 1, null]],
    'columns of another kind' => ['columns', [new stdClass()]],
    'index by hand' => ['indexes', [indexed(['columns' => ['a', 'b'], 'type' => IndexType::Fulltext, 'lengths' => ['a' => 2], 'orders' => ['b' => 'desc'], 'method' => 'gin', 'operatorClass' => 'ops', 'collations' => ['a' => 'C'], 'rawColumns' => ['raw']])]],
    'index of mixed values' => ['indexes', [indexed(['columns' => [1, 2, true], 'lengths' => [1 => '7', 'x' => null], 'orders' => [1 => 'DESC', 2 => 5], 'collations' => [1 => 9]])]],
    'index bad order' => ['indexes', [indexed(['orders' => ['a' => 'up']])]],
    'index bad collation' => ['indexes', [indexed(['collations' => ['a' => 'x;y']])]],
    'index unnamed' => ['indexes', [indexed(['name' => '', 'columns' => []])]],
    'index skipping' => ['indexes', [indexed(['algorithm' => Schema\ClickHouse\IndexAlgorithm::cases()[0], 'algorithmArgs' => [1, 'x', 0.5, true, "it's"], 'granularity' => 0])]],
    'checks by hand' => ['checks', [made(Schema\CheckConstraint::class, ['name' => 'c', 'expression' => 'x > 0; DROP']), made(Schema\CheckConstraint::class, ['name' => '', 'expression' => ''])]],
    'renames by hand' => ['renameColumns', [made(Schema\RenameColumn::class, ['from' => 'a', 'to' => 'b']), made(Schema\RenameColumn::class, ['from' => '', 'to' => 'é'])]],
    'drop columns of mixed values' => ['dropColumns', [1, 2.5, true, 'x']],
    'drop indexes' => ['dropIndexes', ['a', 'b.c', 7]],
    'drop foreign keys' => ['dropForeignKeys', ['a', 7]],
    'raw column defs' => ['rawColumnDefs', ['x INT', '', 3]],
    'raw index defs' => ['rawIndexDefs', ['KEY k (x)', 4]],
    'composite primary' => ['compositePrimaryKey', ['a', 'b.c', 5]],
    'order by' => ['orderBy', ['a', 'b`c', 6]],
    'partition type' => ['partitionType', Schema\PartitionType::List],
    'partition expression' => ['partitionExpression', 'x; y'],
    'partition count' => ['partitionCount', -3],
    'engine' => ['engine', Schema\ClickHouse\Engine::SummingMergeTree],
    'engine args' => ['engineArgs', ['a', 1, "it's"]],
    'ttl' => ['ttl', 'at + 1'],
    'settings' => ['settings', ['a' => '1', 5 => 'x', 'b c' => "it's", 'n' => null, 'i' => 3]],
    'keyed lists' => ['dropColumns', ['x' => 'a', 9 => 'b']],
];
foreach ($schemas as $dialect => $schema) {
    foreach (['table' => CorpusTable::class, 'clickhouse table' => CorpusClickHouseTable::class] as $kind => $class) {
        emit("schema $dialect put on a $kind", array_map(fn (array $put): array => outcome(function () use ($schema, $class, $put): array {
            $table = new $class($schema, 't');
            $table->string('a');
            $table->put($put[0], $put[1]);
            if ($put[0] === 'partitionType' || $put[0] === 'partitionCount') {
                $table->put('partitionType', Schema\PartitionType::Hash)->put('partitionExpression', 'a')->put($put[0], $put[1]);
            }

            return [outcome(fn () => $table->create()), outcome(fn () => $table->alter()), outcome(fn () => $schema->compileCreatePartitioning($table))];
        }), $puts));
    }
    emit("schema $dialect clickhouse properties", outcome(function () use ($schema): array {
        $table = new CorpusClickHouseTable($schema, 't');
        $table->string('a');
        $table->put('sampleBy', 'a')->put('orderByRaw', 'tuple()');

        return [outcome(fn () => $table->create()), outcome(fn () => $table->alter())];
    }));
}

// A blueprint made by one dialect, compiled by every schema; and one with no schema.
$mixed = [];
foreach ($schemas as $dialect => $schema) {
    $table = $schema->table('events');
    $table->id();
    $table->string('name', 120)->nullable()->default("o'k");
    $table->decimal('price', 8, 2)->default(1.5);
    $table->enum('status', ['draft', 'live']);
    $table->timestamps();
    $table->index(['name'], 'by_name', lengths: ['name' => 50], orders: ['name' => 'desc']);
    $table->uniqueIndex(['name', 'status']);
    $mixed[$dialect] = $table;
}
foreach ($schemas as $dialect => $schema) {
    foreach ($mixed as $made => $table) {
        emit("schema $dialect compiles a $made table", [
            outcome(fn () => $schema->compileCreate($table)), outcome(fn () => $schema->compileCreate($table, true)), outcome(fn () => $schema->compileCreate($table, false)),
            outcome(fn () => $schema->compileCreate(ifNotExists: true, table: $table)), outcome(fn () => $schema->compileAlter($table)),
            outcome(fn () => $schema->compileCreatePartitioning($table)), outcome(fn () => $table->createIfNotExists()), outcome(fn () => $table->create(true)),
            outcome(fn () => $schema->compileCreate($table, 1)), outcome(fn () => $schema->compileCreate($table, '')), outcome(fn () => $schema->compileCreate($table, null)),
        ]);
    }
    emit("schema $dialect compiles what is not a table", [
        outcome(fn () => $schema->compileCreate(new stdClass())), outcome(fn () => $schema->compileCreate('t')), outcome(fn () => $schema->compileCreate(null)),
        outcome(fn () => $schema->compileAlter([])), outcome(fn () => $schema->compileCreate()), outcome(fn () => $schema->compileAlter()),
        outcome(fn () => $schema->compileCreate(new Schema\Table())), outcome(fn () => $schema->compileAlter(new Schema\Table(null, 'bare'))),
    ]);
}
emit('schema table without a schema', [
    outcome(fn () => (new Schema\Table(null, 't'))->create()), outcome(fn () => (new Schema\Table())->alter()), outcome(fn () => (new Schema\Table\MySQL(null, 't'))->drop()),
    outcome(fn () => (new Schema\Table(null, 't'))->truncate()), outcome(fn () => (new Schema\Table(null, 't'))->rename('u')),
]);
emit('schema table classes', array_map(fn (Schema $schema): array => [$schema->table('t')::class, $schema->table('t')->name, $schema->table('')->name, $schema->table(5)->name, outcome(fn () => $schema->table()), outcome(fn () => $schema->table([]))], $schemas));

// Every statement method that takes names, with every name.
$special = ['__construct', 'setExecutor', 'table', 'compileCreate', 'compileAlter', 'compileCreatePartitioning', 'createIndex', 'createProcedure', 'createTrigger', 'createView', 'createOrReplaceView', 'createMaterializedView', 'createCollation', 'createType', 'addForeignKey'];
foreach ($schemas as $dialect => $schema) {
    $methods = (new ReflectionClass($schema))->getMethods(ReflectionMethod::IS_PUBLIC);
    usort($methods, fn (ReflectionMethod $a, ReflectionMethod $b): int => strcmp($a->getName(), $b->getName()));
    foreach ($methods as $method) {
        if (in_array($method->getName(), $special, true)) {
            continue;
        }
        $arguments = fn (string $name, bool $flag, int $number): array => array_map(fn (ReflectionParameter $parameter): mixed => match (ltrim((string) $parameter->getType(), '?')) {
            'bool' => $flag,
            'int' => $number,
            default => $name,
        }, $method->getParameters());
        emit("schema $dialect {$method->getName()}", array_map(fn (string $name): array => [
            outcome(fn () => $method->invokeArgs($schema, $arguments($name, true, 7))),
            outcome(fn () => $method->invokeArgs($schema, $arguments($name, false, -1))),
        ], $names));
        emit("schema $dialect {$method->getName()} arguments", [
            outcome(fn () => $method->invoke($schema)), outcome(fn () => $method->invokeArgs($schema, $arguments('x', true, 1) + [9 => 'extra'] === [] ? [] : array_slice($arguments('x', true, 1), 0, -1))),
            outcome(fn () => $method->invokeArgs($schema, array_map(fn (): int => 5, $method->getParameters()))),
            outcome(fn () => $method->invokeArgs($schema, array_map(fn (): float => 1.5, $method->getParameters()))),
            outcome(fn () => $method->invokeArgs($schema, array_map(fn (): bool => true, $method->getParameters()))),
            outcome(fn () => $method->invokeArgs($schema, array_map(fn (): mixed => null, $method->getParameters()))),
            outcome(fn () => $method->invokeArgs($schema, array_map(fn (): array => [], $method->getParameters()))),
        ]);
    }
}

// The statements with arguments of their own.
foreach ($schemas as $dialect => $schema) {
    emit("schema $dialect createIndex", [
        outcome(fn () => $schema->createIndex('t', 'i', ['a'])), outcome(fn () => $schema->createIndex('t', 'i', ['a', 'b.c'], true)),
        outcome(fn () => $schema->createIndex('t', 'i', [])), outcome(fn () => $schema->createIndex('t', 'i', [], rawColumns: ['(lower(a))', 'b DESC'])),
        outcome(fn () => $schema->createIndex('db.t', 'my.i', ['a'], unique: true, type: 'fulltext')), outcome(fn () => $schema->createIndex('t', 'i', ['a'], type: 'fulltext')),
        outcome(fn () => $schema->createIndex('t', 'i', ['a'], type: 'spatial')), outcome(fn () => $schema->createIndex('t', 'i', ['a'], type: 'unique')),
        outcome(fn () => $schema->createIndex('t', 'i', ['a'], type: 'index')), outcome(fn () => $schema->createIndex('t', 'i', ['a'], type: 'nope')),
        outcome(fn () => $schema->createIndex('t', 'i', ['a'], type: "\xff")), outcome(fn () => $schema->createIndex('t', 'i', ['a'], true, 'nope')),
        outcome(fn () => $schema->createIndex('t', 'i', ['a'], method: 'gin')), outcome(fn () => $schema->createIndex('t', 'i', ['a'], method: 'hnsw', operatorClass: 'vector_cosine_ops')),
        outcome(fn () => $schema->createIndex('t', 'i', ['a'], method: 'bTree; x')), outcome(fn () => $schema->createIndex('t', 'i', ['a'], operatorClass: 'ops; x')),
        outcome(fn () => $schema->createIndex('t', 'i', ['a', 'b'], lengths: ['a' => 10, 'b' => '5', 'c' => 1])), outcome(fn () => $schema->createIndex('t', 'i', ['a'], lengths: ['a' => 7.0])),
        outcome(fn () => $schema->createIndex('t', 'i', ['a', 'b', 'c'], orders: ['a' => 'desc', 'b' => 'ASC', 'c' => 'Desc'])), outcome(fn () => $schema->createIndex('t', 'i', ['a'], orders: ['a' => 'down'])),
        outcome(fn () => $schema->createIndex('t', 'i', ['a'], collations: ['a' => 'utf8mb4_bin'])), outcome(fn () => $schema->createIndex('t', 'i', ['a'], collations: ['a' => 'en-US'])),
        outcome(fn () => $schema->createIndex('t', 'i', ['a', 'b'], false, '', 'btree', 'ops', ['a' => 1], ['a' => 'asc'], ['b' => 'C'], ['raw'])),
        outcome(fn () => $schema->createIndex('t', 'i', ['a'], concurrently: true)), outcome(fn () => $schema->createIndex('t', 'i', ['a'], unique: true, concurrently: true, method: 'btree')),
        outcome(fn () => $schema->createIndex('t', 'i', ['1', 2], orders: [1 => 'desc', '2' => 'desc'], lengths: [2 => 4])), outcome(fn () => $schema->createIndex('t', 'i', ['a'], orders: ['a' => null], lengths: ['a' => null])),
        outcome(fn () => $schema->createIndex('t', 'i', 'a')), outcome(fn () => $schema->createIndex('t', 'i')), outcome(fn () => $schema->createIndex('t', 'i', ['a'], 'yes')), outcome(fn () => $schema->createIndex('t', 'i', ['a'], lengths: 'x')),
        outcome(fn () => $schema->createIndex('t', 'i', [['a']])), outcome(fn () => $schema->createIndex('t', 'i', ['a'], nope: 1)),
    ]);
    emit("schema $dialect foreign keys", array_merge(
        [outcome(fn () => $schema->addForeignKey('t', 'fk', 'c', 'other', 'id')), outcome(fn () => $schema->addForeignKey('db.t', 'my.fk', 'a.b', 'db.other', 'x.id', null, null))],
        array_map(fn (ForeignKeyAction $action): array => [outcome(fn () => $schema->addForeignKey('t', 'fk', 'c', 'o', 'id', $action)), outcome(fn () => $schema->addForeignKey('t', 'fk', 'c', 'o', 'id', onUpdate: $action)), outcome(fn () => $schema->addForeignKey('t', 'fk', 'c', 'o', 'id', $action, $action))], ForeignKeyAction::cases()),
        [outcome(fn () => $schema->addForeignKey('t', 'fk', 'c', 'o', 'id', 'CASCADE')), outcome(fn () => $schema->addForeignKey('t', 'fk', 'c', 'o', 'id', IndexType::Index)), outcome(fn () => $schema->addForeignKey('t', 'fk', 'c', 'o'))],
    ));
    emit("schema $dialect procedures", [
        outcome(fn () => $schema->createProcedure('p', [], 'SELECT 1;')),
        outcome(fn () => $schema->createProcedure('db.p', [[ParameterDirection::In, 'a', 'INT'], [ParameterDirection::Out, 'b', 'VARCHAR(10)'], [ParameterDirection::InOut, 'c.d', 'DOUBLE PRECISION']], "SELECT 'x';")),
        outcome(fn () => $schema->createProcedure('p', [[ParameterDirection::In, 'a', 'INT; DROP']], '')), outcome(fn () => $schema->createProcedure('p', [[ParameterDirection::In, 'a', '']], '')),
        outcome(fn () => $schema->createProcedure('p', [[ParameterDirection::In, 'a', "INT\n"]], 'x')), outcome(fn () => $schema->createProcedure('p', [[ParameterDirection::In, 'a', 'NUMERIC ( 10 , 2 )']], 'x')),
        outcome(fn () => $schema->createProcedure('p', [[ParameterDirection::In, 'a', 'INT']], 'x $$ y')), outcome(fn () => $schema->createProcedure('p', [[ParameterDirection::In, 'a', 'INT']], 'x $body$ y')),
        outcome(fn () => $schema->createProcedure('p', 'a', 'x')), outcome(fn () => $schema->createProcedure('p', [[ParameterDirection::In, 5, 'INT']], 'x')),
    ]);
    emit("schema $dialect triggers", array_merge(
        array_merge(...array_map(fn (TriggerTiming $timing): array => array_map(fn (TriggerEvent $event): array => outcome(fn () => $schema->createTrigger('trg', 'db.t', $timing, $event, 'SET NEW.a = 1;')), TriggerEvent::cases()), TriggerTiming::cases())),
        [outcome(fn () => $schema->createTrigger('my.trg', 't', TriggerTiming::Before, TriggerEvent::Insert, 'x $$ y')), outcome(fn () => $schema->createTrigger('trg', 't', TriggerTiming::Before, TriggerEvent::Insert, '')),
            outcome(fn () => $schema->createTrigger('trg', 't', 'BEFORE', 'INSERT', 'x')), outcome(fn () => $schema->createTrigger('trg', 't', TriggerEvent::Insert, TriggerTiming::Before, 'x')),
            outcome(fn () => $schema->dropTrigger('trg')), outcome(fn () => $schema->dropTrigger('trg', 't')), outcome(fn () => $schema->dropTrigger('my.trg', 'db.t')), outcome(fn () => $schema->dropTrigger('trg', null)), outcome(fn () => $schema->dropTrigger('trg', table: 'x`y'))],
    ));
    emit("schema $dialect postgres statements", [
        outcome(fn () => $schema->createCollation('c', ['provider' => 'icu', 'locale' => 'und-u-ks-level1'])), outcome(fn () => $schema->createCollation('my.c', ['provider' => "it's"], false)),
        outcome(fn () => $schema->createCollation('c', [])), outcome(fn () => $schema->createCollation('c', ['pro vider' => 'icu'])), outcome(fn () => $schema->createCollation('c', ['a', 'b'])),
        outcome(fn () => $schema->createCollation('c', ['n' => 5, 'f' => 1.5, 't' => true], deterministic: true)), outcome(fn () => $schema->createCollation('c', ['a' => ['x'], 'b' => null])),
        outcome(fn () => $schema->createType('mood', ['happy', 'sad', "it's", 'back\\slash'])), outcome(fn () => $schema->createType('my.mood', [])), outcome(fn () => $schema->createType('mood', [1, 2.5, true, null])),
        outcome(fn () => $schema->createSequence('s')), outcome(fn () => $schema->createSequence('s', 10)), outcome(fn () => $schema->createSequence('my.s', -5, 0)), outcome(fn () => $schema->createSequence('s', incrementBy: 3)),
        outcome(fn () => $schema->createSequence('s', '12', 2.0)), outcome(fn () => $schema->createSequence('s', 'x')), outcome(fn () => $schema->createSequence('s', PHP_INT_MAX, PHP_INT_MIN)),
        outcome(fn () => $schema->alterColumnType('t', 'c', 'VARCHAR(10)')), outcome(fn () => $schema->alterColumnType('t', 'c', 'INTEGER', 'c::integer')), outcome(fn () => $schema->alterColumnType('t', 'c', 'INTEGER', using: 'c; DROP')),
        outcome(fn () => $schema->alterColumnType('t', 'c', 'INT; x')), outcome(fn () => $schema->alterColumnType('t', 'c', 'INTEGER', 'a -- b')), outcome(fn () => $schema->alterColumnType('t', 'c', 'INTEGER', 'a /* b')),
        outcome(fn () => $schema->alterColumnNullable('t', 'c', true)), outcome(fn () => $schema->alterColumnNullable('db.t', 'a.b', false)), outcome(fn () => $schema->alterColumnNullable('t', 'c', 0)),
        outcome(fn () => $schema->changeColumn('t', 'a', 'b', 'VARCHAR(10) NOT NULL')), outcome(fn () => $schema->modifyColumn('t', 'a', 'TEXT')),
    ]);
    emit("schema $dialect comments", array_map(fn (string $comment): array => [
        outcome(fn () => $schema->commentOnTable('t', $comment)), outcome(fn () => $schema->commentOnColumn('t', 'c', $comment)), outcome(fn () => $schema->createPartition('t', 'p', $comment)),
    ], ['plain', '', "it's", 'back\\slash', "\\'", "é", "\xff", "line\nbreak", 'VALUES LESS THAN (10)', "FOR VALUES FROM ('a') TO ('b')"]));
}

// Views: the builder is PHP's, and its bindings ride along.
$builders = [
    'mysql' => fn () => (new Builder\MySQL())->from('users')->filter([Query::equal('active', [true]), Query::greaterThan('age', 18)]),
    'postgres' => fn () => (new Builder\PostgreSQL())->from('users')->select(['name'])->filter([Query::equal('name', ["o'k", "\xff"])]),
    'sqlite' => fn () => (new Builder\SQLite())->from('users'),
    'clickhouse' => fn () => (new Builder\ClickHouse())->from('events')->filter([Query::equal('status', ['active'])]),
    'mongo' => fn () => (new Builder\MongoDB())->from('users')->select(['name', 'email'])->filter([Query::equal('active', [true])]),
    'unbuildable' => fn () => new Builder\MySQL(),
];
foreach ($schemas as $dialect => $schema) {
    foreach ($builders as $made => $builder) {
        emit("schema $dialect views over a $made builder", [
            outcome(fn () => $schema->createView('v', $builder())), outcome(fn () => $schema->createView('db.my`v', $builder())), outcome(fn () => $schema->createOrReplaceView('v', $builder())),
            outcome(fn () => $schema->createMaterializedView('mv', $builder())), outcome(fn () => $schema->createMaterializedView('mv', $builder(), 'target.t', false)),
            outcome(fn () => $schema->createMaterializedView('mv', $builder(), ifNotExists: false)),
        ]);
    }
    emit("schema $dialect views", [
        outcome(fn () => $schema->createMaterializedView('mv', 'SELECT 1')), outcome(fn () => $schema->createMaterializedView('mv', "SELECT '\xff'", 't')), outcome(fn () => $schema->createMaterializedView('mv', '', null, true)),
        outcome(fn () => $schema->createMaterializedView('mv', 5)), outcome(fn () => $schema->createMaterializedView('mv', null)), outcome(fn () => $schema->createMaterializedView('mv', new stdClass())),
        outcome(fn () => $schema->createMaterializedView('mv', [])), outcome(fn () => $schema->dropMaterializedView('mv')), outcome(fn () => $schema->dropMaterializedView('db.mv', false)),
        outcome(fn () => $schema->createView('v', 'SELECT 1')), outcome(fn () => $schema->createView('v', null)), outcome(fn () => $schema->createView('v', new stdClass())), outcome(fn () => $schema->createView('v')),
        outcome(fn () => $schema->createOrReplaceView('v', $schema)),
    ]);
}

// The executor: set on the schema, carried by every statement it compiles from then on.
foreach ($schemas as $dialect => $schema) {
    emit("schema $dialect executor", outcome(function () use ($schema): array {
        $fresh = new ($schema::class)();
        $before = $fresh->table('t')->drop();
        $ran = [];
        $returned = $fresh->setExecutor(function (Statement $statement) use (&$ran): array {
            $ran[] = $statement->query;

            return ['ran' => count($ran)];
        });
        $table = $fresh->table('t');
        $table->string('a');
        $copy = clone $fresh;
        $second = $fresh->setExecutor(fn (Statement $statement): int => 42);

        return [
            $returned === $fresh, $second === $fresh, outcome(fn () => $before->execute()), outcome(fn () => $schema->compileDrop('t', false)->execute()),
            $copy->compileDrop('a', true)->execute(), $copy->compileTruncate('b')->execute(), $table->create()->execute(), $fresh->compileRename('a', 'b')->execute(),
            $ran, $fresh->compileDrop('t', false)->withExecutor(fn (): int => 7)->execute(),
            outcome(fn () => $fresh->setExecutor('strlen')), outcome(fn () => $fresh->setExecutor(null)), outcome(fn () => $fresh->setExecutor()), outcome(fn () => $fresh->setExecutor(strlen(...)) === $fresh),
        ];
    }));
}

emit('schema instances', array_map(fn (Schema $schema): array => array_merge(
    [$schema instanceof Schema, $schema instanceof Schema\SQL],
    array_map(fn (string $feature): bool => $schema instanceof ('Utopia\\Query\\Schema\\Feature\\' . $feature), ['AnalyzeTable', 'ColumnComments', 'CreatePartition', 'Databases', 'DropPartition', 'ForeignKeys', 'MaterializedViews', 'Partitioning', 'Procedures', 'RenameIndex', 'ReplaceView', 'Sequences', 'TableComments', 'Triggers', 'Types', 'Views']),
), $schemas));
emit('schema signatures', array_map(function (Schema $schema): array {
    $signatures = [];
    foreach ((new ReflectionClass($schema))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getName() !== '__construct') {
            $signatures[$method->getName()] = array_map(fn (ReflectionParameter $parameter): array => [$parameter->getName(), $parameter->isOptional(), $parameter->isOptional() ? exported($parameter->getDefaultValue()) : null], $method->getParameters());
        }
    }
    ksort($signatures);

    return $signatures;
}, $schemas));
emit('schema abstract classes', [outcome(fn () => new Schema()), outcome(fn () => new Schema\SQL()), (new ReflectionClass(Schema::class))->isAbstract(), (new ReflectionClass(Schema\SQL::class))->isAbstract()]);
emit('schema typed parameter', outcome(fn () => (fn (Schema $any, Schema\SQL $sql, Schema\Feature\Views $views): string => $views->dropView('v')->query)($schemas['mongo'], $schemas['sqlite'], $schemas['clickhouse'])));
emit('schema subclass', outcome(function (): array {
    $own = new class () extends Schema\MySQL {
        public function compileDrop(string $name, bool $ifExists): Statement
        {
            return new Statement('-- ' . parent::compileDrop($name, $ifExists)->query, []);
        }
    };

    return [$own->table('t')->drop(), $own->table('t')->dropIfExists(), $own->table('t')->truncate(), $own->table('t')::class, $own instanceof Schema\SQL];
}));

// A builder may hand back a statement whose constructor never ran: reading it is PHP's error.
readonly class HollowStatement extends Utopia\Query\Builder\Statement
{
    public function __construct()
    {
    }
}
class HollowBuilder extends Utopia\Query\Builder\MySQL
{
    public function build(): Utopia\Query\Builder\Statement
    {
        return new HollowStatement();
    }
}
emit('view of a hollow statement', outcome(fn () => (new Utopia\Query\Schema\MySQL())->createView('v', new HollowBuilder())));

// --- String literals -----------------------------------------------------------------------

// Each dialect writes and reads a string literal by its own rules, and takes another's when given one.
$rules = Utopia\Query\StringLiteral::cases();
$texts = [
    '', 'plain', "it's", 'back\\slash', '\\', 'a\\', "nul\0byte", "é\\", "\xff\\", "\xe9\\x", "a\\\xff", "\x80", 'tab\\tnl\\n',
    "\\'", "''", '%_\\%\\_', '日本\\語', "line\nbreak",
];
$literals = [
    "''", "'a'", "'it''s'", "'a\\'b'", "'a\\\\b'", "'\\n\\t\\r\\b\\0\\Z\\a\\f\\v\\%\\_\\q'", "'\\x41\\x4\\xg\\xff'", "'\\101\\7\\400\\8'",
    "'\\u00e9\\u12\\U0001F600\\U0000d800\\uD800'", "'\\U00110000'", "E'a\\nb'", "e'\\x41'", "E'\\u00e9'", "E'\\0'", "E'\\101'", "E''", "E'it''s'",
    "'\xff'", "'\xff\\n'", "E'\xe9\\xe9\\u00e9'", "'a\\", "'a'b'", "'", "E'", "E'\\'", "'nul\0'", "'\\0'", 'E', "'a' 'b'",
];
foreach ($rules as $rule) {
    $classes = [Tokenizer\Tokenizer::class, Tokenizer\MySQL::class, Tokenizer\MariaDB::class, Tokenizer\PostgreSQL::class, Tokenizer\SQLite::class, Tokenizer\ClickHouse::class];
    foreach ($classes as $class) {
        $read = [];
        foreach ($literals as $literal) {
            $read[] = outcome(fn () => (new $class($rule))->tokenize('SELECT ' . $literal . ' # c' . "\n" . ', `a`, "b" -- d'));
        }
        emit('literal tokens ' . $rule->name . ' ' . $class, $read);
    }
    $parsed = [];
    foreach ($literals as $literal) {
        foreach ([Tokenizer\Tokenizer::class, Tokenizer\PostgreSQL::class] as $class) {
            $parsed[] = outcome(fn () => (new AST\Parser($rule))->parse(Tokenizer\Tokenizer::filter((new $class($rule))->tokenize('SELECT ' . $literal))));
        }
    }
    emit('literal parsed ' . $rule->name, $parsed);
    $written = [];
    foreach ([AST\Serializer::class, AST\Serializer\MySQL::class, AST\Serializer\MariaDB::class, AST\Serializer\PostgreSQL::class, AST\Serializer\SQLite::class, AST\Serializer\ClickHouse::class] as $class) {
        foreach ($texts as $text) {
            $written[] = outcome(fn () => (new $class($rule))->serializeExpression(new AST\Literal($text)));
        }
    }
    emit('literal written ' . $rule->name, $written);
}
// The defaults of each class, and a literal read back from what was written.
$defaults = [];
foreach ([[Tokenizer\Tokenizer::class, AST\Serializer::class], [Tokenizer\MySQL::class, AST\Serializer\MySQL::class], [Tokenizer\PostgreSQL::class, AST\Serializer\PostgreSQL::class], [Tokenizer\SQLite::class, AST\Serializer\SQLite::class], [Tokenizer\ClickHouse::class, AST\Serializer\ClickHouse::class]] as [$tokenizer, $serializer]) {
    foreach ($texts as $text) {
        $defaults[] = outcome(fn () => (new $serializer())->serializeExpression(new AST\Literal($text)));
        $defaults[] = outcome(fn () => (new $tokenizer())->tokenize("SELECT 'a\\'b', E'c\\'d', e'f'"));
    }
}
emit('literal defaults', $defaults);

// LIKE keeps its ESCAPE clause, and nothing may follow a statement but one semicolon.
$likes = [];
foreach ([
    "SELECT a LIKE 'x!%' ESCAPE '!'", "SELECT a NOT LIKE b ESCAPE c", "SELECT a ILIKE 'x' escape '\\\\' AND b", "SELECT a NOT ILIKE 'x' ESCAPE '!' OR b LIKE 'y'",
    "SELECT a LIKE 'x' ESCAPE", "SELECT a LIKE 'x' ESCAPE '!' ESCAPE '?'", 'SELECT escape FROM t', 'SELECT a = b ESCAPE c', 'SELECT 1;', 'SELECT 1;;', 'SELECT 1; SELECT 2',
    'SELECT 1 2', 'SELECT 1 FROM t )', 'SELECT a FROM t WHERE x LIKE y ESCAPE z ORDER BY a;',
] as $sql) {
    foreach ([[new Tokenizer\Tokenizer(), new AST\Parser(), new AST\Serializer()], [new Tokenizer\PostgreSQL(), new AST\Parser(Utopia\Query\StringLiteral::Extended), new AST\Serializer\PostgreSQL()]] as [$tokenizer, $reader, $writer]) {
        $likes[] = outcome(fn () => $reader->parse(Tokenizer\Tokenizer::filter($tokenizer->tokenize($sql))));
        $likes[] = outcome(fn () => $writer->serialize($reader->parse(Tokenizer\Tokenizer::filter($tokenizer->tokenize($sql)))));
    }
}
$likes[] = outcome(fn () => (new AST\Serializer())->serializeExpression(new AST\Expression\Binary(new AST\Reference\Column('a'), 'LIKE', new AST\Literal('x'), new AST\Expression\Binary(new AST\Literal('!'), 'OR', new AST\Literal('?')))));
emit('like escape and trailing input', $likes);

// DDL inlines its strings through the same rules.
$inlined = [];
foreach ($schemas as $dialect => $schema) {
    foreach ($texts as $text) {
        $inlined[] = outcome(function () use ($schema, $text) {
            $table = $schema->table('t');
            $table->string('a')->default($text)->comment($text);
            $table->enum('e', [$text, 'b']);

            return $table->create();
        });
        if ($schema instanceof Schema\Feature\TableComments) {
            $inlined[] = outcome(fn () => $schema->commentOnTable('t', $text));
        }
        if ($schema instanceof Schema\Feature\ColumnComments) {
            $inlined[] = outcome(fn () => $schema->commentOnColumn('t', 'c', $text));
        }
        if ($schema instanceof Schema\Feature\DropPartition) {
            $inlined[] = outcome(fn () => $schema->dropPartition('t', $text));
        }
        if ($schema instanceof Schema\PostgreSQL) {
            $inlined[] = outcome(fn () => $schema->createType('m', [$text, 'b']));
            $inlined[] = outcome(fn () => $schema->nextVal($text));
            $inlined[] = outcome(fn () => $schema->createCollation('c', ['a' => $text, 'b' => 1, 'c' => null]));
            $inlined[] = outcome(fn () => $schema->createCollation('c', ['bad key' => $text, 'c' => []]));
        }
    }
}
emit('literal in DDL', $inlined);

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
}, array_values(array_diff(NATIVE, [Query::class])))));
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

// PHP keeps the SQL it last read, its length and its position on the tokenizer, so one that has
// tokenized is not == a new one; the extension's holds nothing.
$usedEqualsNew = $tokenizers['mysql'] == new Tokenizer\MySQL();
if ($usedEqualsNew !== $requested) {
    fwrite(STDERR, 'A used tokenizer is ' . ($usedEqualsNew ? '' : 'not ') . '== a new one: the known difference changed' . PHP_EOL);
    exit(1);
}
// PHP asks getIdentifierQuoteChar() on every tokenize(), so a subclass can change the quote; the
// extension's dialects are fixed.
$piped = new class () extends Tokenizer\Tokenizer {
    protected function getIdentifierQuoteChar(): string
    {
        return '|';
    }
};
$consulted = $piped->tokenize('|a|')[0]->type === TokenType::QuotedIdentifier;
if ($consulted === $requested) {
    fwrite(STDERR, 'An overridden getIdentifierQuoteChar() is ' . ($consulted ? '' : 'not ') . 'consulted: the known difference changed' . PHP_EOL);
    exit(1);
}

// On the extension Query is the package's class over the extension's NativeQuery, which holds its
// methods; in PHP it has no parent. Its constants are the same either way.
$parent = get_parent_class(Query::class);
if (($parent === 'Utopia\\Query\\NativeQuery') !== $requested) {
    fwrite(STDERR, 'The parent of Query is ' . var_export($parent, true) . ': the known difference changed' . PHP_EOL);
    exit(1);
}
// isset() on a protected property from outside the class is true on the extension, as it is for
// every class with getter-backed properties; reading it is refused either way.
$visible = isset(Query::limit(1)->method);
if ($visible !== $requested) {
    fwrite(STDERR, 'isset() of a protected property is ' . var_export($visible, true) . ': the known difference changed' . PHP_EOL);
    exit(1);
}
// PHP ignores arguments past the last parameter; the extension counts them.
$extra = outcome(fn () => exported(Query::limit(1, 2)));
if (isset($extra['throws']) !== $requested) {
    fwrite(STDERR, 'An extra argument gives ' . json_encode($extra) . ': the known difference changed' . PHP_EOL);
    exit(1);
}

// PHP's serialize() calls serializeExpression() and quoteIdentifier() on $this, so a subclass's own take part in a
// statement; the extension's serializers write a whole statement themselves.
$own = new class () extends AST\Serializer {
    public function serializeExpression(AST\Expression $expression): string
    {
        return '<' . parent::serializeExpression($expression) . '>';
    }

    protected function quoteIdentifier(string $name): string
    {
        return '[' . $name . ']';
    }
};
$consulted = $own->serialize(new AST\Statement\Select([new AST\Reference\Column('a')], new AST\Reference\Table('t'))) !== 'SELECT `a` FROM `t`';
if ($consulted === $requested) {
    fwrite(STDERR, 'A serializer subclass\'s serializeExpression() and quoteIdentifier() are ' . ($consulted ? '' : 'not ') . 'consulted by serialize(): the known difference changed' . PHP_EOL);
    exit(1);
}
// PHP parses a chain of NOT or sign operators as long as its memory allows; the extension stops at 1024.
$chained = outcome(fn () => (new AST\Parser())->parse(Tokenizer\Tokenizer::filter((new Tokenizer\Tokenizer())->tokenize('select ' . str_repeat('not ', 1100) . 'a'))))['throws'] ?? null;
if (($chained === null) === $requested) {
    fwrite(STDERR, 'A chain of 1100 NOTs ' . ($chained === null ? 'parses' : 'is refused') . ': the known difference changed' . PHP_EOL);
    exit(1);
}

// The schemas. PHP's dialects are built from protected steps (quote(), compileColumnType(), ...) that a
// subclass can override or call; the extension's compile a statement whole and have only the public methods.
// The abstract Schema has none of its own for the same reason.
$steps = [method_exists(Schema\MySQL::class, 'quote'), method_exists(Schema\MySQL::class, 'compileColumnType'), method_exists(Schema::class, 'compileCreate')];
if ($steps !== [! $requested, ! $requested, ! $requested]) {
    fwrite(STDERR, 'The protected steps of a schema are ' . json_encode($steps) . ': the known difference changed' . PHP_EOL);
    exit(1);
}
// The Feature interfaces declare no methods on the extension; the classes that implement them have every one.
$declared = (new ReflectionClass(Schema\Feature\Views::class))->getMethods() !== [];
if ($declared === $requested) {
    fwrite(STDERR, 'Feature\\Views does ' . ($declared ? '' : 'not ') . 'declare its methods: the known difference changed' . PHP_EOL);
    exit(1);
}
// PHP reads from a blueprint only what the statement uses; the extension reads all of it first. The two differ
// only on a blueprint holding what its fluent methods refuse, which takes a subclass or reflection to make.
$halfMade = new CorpusTable($schemas['mysql'], 't');
$halfMade->put('checks', [made(Schema\CheckConstraint::class, ['name' => 'c'])]);
$read = isset(outcome(fn () => $halfMade->alter())['throws']);
if ($read !== $requested) {
    fwrite(STDERR, 'ALTER TABLE does ' . ($read ? '' : 'not ') . 'read a check it does not write: the known difference changed' . PHP_EOL);
    exit(1);
}
// PHP writes an index length as it is given; the extension takes a number.
$length = outcome(fn () => $schemas['mysql']->createIndex('t', 'i', ['a'], lengths: ['a' => 'x'])->query);
if (($length === ['value' => 'CREATE INDEX `i` ON `t` (`a`(x))']) === $requested) {
    fwrite(STDERR, 'An index length that is not a number gives ' . json_encode($length) . ': the known difference changed' . PHP_EOL);
    exit(1);
}
// PHP reads a procedure parameter's direction off anything, with a warning; the extension takes the enum.
$direction = outcome(fn () => $schemas['mysql']->createProcedure('p', [['IN', 'a', 'INT']], 'x')->query);
if (isset($direction['throws']) !== $requested) {
    fwrite(STDERR, 'A procedure parameter without a ParameterDirection gives ' . json_encode($direction) . ': the known difference changed' . PHP_EOL);
    exit(1);
}
// MongoDB's statements are JSON. PHP refuses a string that is not UTF-8 when it encodes it; the extension when
// it reads it, so also where the statement does not use it.
$unused = $schemas['mongo']->table('t');
$unused->index(["\xff"], 'i');
$refused = isset(outcome(fn () => $unused->create())['throws']);
if ($refused !== $requested) {
    fwrite(STDERR, 'A MongoDB blueprint with an unused string that is not UTF-8 is ' . ($refused ? '' : 'not ') . 'refused: the known difference changed' . PHP_EOL);
    exit(1);
}
// Reading a query that holds itself: PHP builds arrays until memory runs out, which is fatal. The extension
// throws the engine's error for infinite recursion, so only the extension can run these.
if ($requested) {
    $reads = [
        'toString' => fn () => $looped->toString(),
        'toArray' => fn () => $looped->toArray(),
        'shape' => fn () => $looped->shape(),
        'fingerprint' => fn () => Query::fingerprint([$looped]),
        'validate' => fn () => Query::validate([$looped], ['a']),
    ];
    foreach ($reads as $name => $read) {
        $ended = outcome($read);
        if (($ended['throws'] ?? null) !== Error::class) {
            fwrite(STDERR, $name . '() of a query that holds itself gives ' . json_encode($ended) . ': the known difference changed' . PHP_EOL);
            exit(1);
        }
    }
}
// The rules a tokenizer, a serializer and a schema were given are a protected property in PHP. The extension
// keeps them in Rust, so a subclass cannot read `$this->literal`.
$held = [property_exists(Tokenizer\Tokenizer::class, 'literal'), property_exists(AST\Serializer::class, 'literal'), property_exists(Schema::class, 'literal')];
if ($held !== [! $requested, ! $requested, ! $requested]) {
    fwrite(STDERR, 'The literal property is ' . json_encode($held) . ': the known difference changed' . PHP_EOL);
    exit(1);
}

// serialize() and unserialize(): the text is what PHP's own class writes, so one reads what the other wrote.
// utopia-php/database hashes it for its cache keys. A subclass keeps the properties it declares.
class SerializedQuery extends Query
{
    public int $extra = 3;

    /** @var array<string, int> */
    protected array $more = ['k' => 1];

    private ?string $own = 'p';

    /** @return array<mixed> */
    public function held(): array
    {
        return [$this->extra, $this->more, $this->own];
    }

    public function change(): static
    {
        $this->extra = 9;
        $this->more = [];
        $this->own = null;

        return $this;
    }
}
$nested = Query::or([Query::equal('a', [1, 'x', 1.5, null, true]), new Query(Method::Join, 't', [], 't'), Query::and([Query::isNull("b\0c")])]);
$nested->setOnArray(true);
$typed = new SerializedQuery(Method::Equal, 'a', [1]);
$typed->setAttributeType('object');
foreach (['plain' => Query::limit(5), 'nested' => $nested, 'subclass' => $typed, 'changed' => (new SerializedQuery(Method::Select, '', ['a']))->change()] as $name => $query) {
    $text = serialize($query);
    $back = unserialize($text);
    emit('serialize ' . $name, [
        bin2hex($text),
        md5(serialize(['queries' => [$query], 'context' => ['k' => 1]])),
        $back::class,
        $back->toString(),
        [$back->onArray(), $back->getAttributeType(), $back->getAlias(), $back->getMethod()->value],
        $back instanceof SerializedQuery ? $back->held() : null,
        serialize($back) === $text,
        serialize(clone $back) === $text,
    ]);
}
emit('unserialize', [
    outcome(fn () => unserialize('O:18:"Utopia\Query\Query":0:{}')->getAttribute()),
    outcome(fn () => unserialize('O:18:"Utopia\Query\Query":1:{s:9:"' . "\0*\0" . 'method";s:3:"bad";}')),
    outcome(fn () => unserialize('O:18:"Utopia\Query\Query":2:{s:9:"' . "\0*\0" . 'method";E:25:"Utopia\Query\Method:Equal";s:5:"other";i:1;}')->toString()),
]);
