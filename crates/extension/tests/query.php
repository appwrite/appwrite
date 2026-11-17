<?php

// Parity corpus for the part of Utopia\Query the utopia extension has so far: the statement
// classifiers with the Type they return, the tokenizers, and Query. Run it on the PHP classes and on the extension's
// (_APP_EXTENSION_LIBRARIES=query), and diff stdout: every line must match. Stderr names the
// implementation that ran.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Query\Builder\ParsedQuery;
use Utopia\Query\Classifier;
use Utopia\Query\Classifier\MongoDB;
use Utopia\Query\Classifier\MySQL;
use Utopia\Query\Classifier\PostgreSQL;
use Utopia\Query\Classifier\SQL;
use Utopia\Query\Compiler;
use Utopia\Query\CursorDirection;
use Utopia\Query\Method;
use Utopia\Query\NullsPosition;
use Utopia\Query\Query;
use Utopia\Query\Tokenizer;
use Utopia\Query\Tokenizer\Token;
use Utopia\Query\Tokenizer\TokenType;
use Utopia\Query\Type;

const NATIVE = [
    Type::class, Classifier::class, SQL::class, MySQL::class, PostgreSQL::class, MongoDB::class,
    Tokenizer\Tokenizer::class, Tokenizer\MySQL::class, Tokenizer\MariaDB::class, Tokenizer\PostgreSQL::class,
    Tokenizer\SQLite::class, Tokenizer\ClickHouse::class, Query::class,
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

// serialize() refuses a class with Rust state. Nothing in Appwrite serializes a query.
$serialized = outcome(fn () => strlen(serialize(Query::limit(1))) > 0);
if (isset($serialized['throws']) !== $requested) {
    fwrite(STDERR, 'serialize() gives ' . json_encode($serialized) . ': the known difference changed' . PHP_EOL);
    exit(1);
}
