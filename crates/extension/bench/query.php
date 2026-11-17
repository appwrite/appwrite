<?php

// Times the Utopia\Query classifiers, tokenizers, AST parser and serializers, and Query on whichever implementation loaded. Compare both with
// `python3 crates/extension/bench/run.py crates/extension/bench/query.php . <utopia.so> 5`.

require __DIR__ . '/../../../vendor/autoload.php';

use Utopia\Query\Classifier\MongoDB;
use Utopia\Query\Classifier\MySQL;
use Utopia\Query\Classifier\PostgreSQL;
use Utopia\Query\Method;
use Utopia\Query\Query;
use Utopia\Query\Tokenizer\Tokenizer;

// A run meant for the extension that gets the PHP classes would time PHP against PHP.
$internal = (new ReflectionClass(MySQL::class))->isInternal();
$requested = in_array('query', array_map(trim(...), explode(',', getenv('_APP_EXTENSION_LIBRARIES') ?: '')), true);
if ($internal !== $requested) {
    fwrite(STDERR, '_APP_EXTENSION_LIBRARIES ' . ($requested ? 'names' : 'does not name') . ' query, but the other implementation loaded' . PHP_EOL);
    exit(1);
}

$mysql = new MySQL();
$postgres = new PostgreSQL();
$mongo = new MongoDB();
$tokenizer = new Tokenizer();
$mysqlTokenizer = new Utopia\Query\Tokenizer\MySQL();
$postgresTokenizer = new Utopia\Query\Tokenizer\PostgreSQL();

// What a database proxy sees, one packet at a time: a simple query, a commented one, a CTE,
// a prepared statement and a MongoDB command.
$select = 'SELECT `_uid`, `name`, `email` FROM `app_users` WHERE `_tenant` = 42 AND `status` = \'active\' ORDER BY `_id` DESC LIMIT 25';
$commented = "/* request 7f3a */\n-- trace\n  UPDATE `app_users` SET `name` = 'x' WHERE `_uid` = 'abc'";
$cte = 'WITH recent AS (SELECT * FROM events WHERE time > now() - interval \'1 day\'), ranked AS (SELECT * FROM recent) INSERT INTO daily SELECT * FROM ranked';
$element = fn (string $key, string $value): string => "\x02" . $key . "\x00" . pack('V', strlen($value) + 1) . $value . "\x00";
$command = function (string ...$elements): string {
    $body = implode('', $elements) . "\x00";
    $body = pack('V', 0) . "\x00" . pack('V', 4 + strlen($body)) . $body;

    return pack('V', 16 + strlen($body)) . pack('V', 1) . pack('V', 0) . pack('V', 2013) . $body;
};
$find = $command($element('find', 'users'), $element('$db', 'app'));
$insert = $command($element('insert', 'users'), $element('$db', 'app'), $element('comment', str_repeat('x', 200)), "\x08startTransaction\x00\x01");

// What the builder hands the AST: a filtered token list to parse, and the statement to write back.
$parser = new Utopia\Query\AST\Parser();
$serializer = new Utopia\Query\AST\Serializer();
$postgresSerializer = new Utopia\Query\AST\Serializer\PostgreSQL();
$report = 'WITH recent AS (SELECT user_id, count(*) AS events FROM events WHERE time > 100 AND kind IN (\'a\', \'b\', \'c\') GROUP BY user_id HAVING count(*) > 5) '
    . 'SELECT u.id, u.name, coalesce(r.events, 0) AS events, CASE WHEN u.age >= 18 THEN \'adult\' ELSE \'minor\' END AS band, row_number() OVER (PARTITION BY u.team ORDER BY r.events DESC) AS position '
    . 'FROM users AS u LEFT JOIN recent AS r ON r.user_id = u.id INNER JOIN teams AS t ON t.id = u.team '
    . 'WHERE u.status = \'active\' AND (u.age BETWEEN 18 AND 65 OR u.role = \'admin\') AND NOT EXISTS (SELECT 1 FROM bans AS b WHERE b.user_id = u.id) ORDER BY events DESC NULLS LAST, u.name ASC LIMIT 50 OFFSET 100';
$wide = 'SELECT a FROM t WHERE a = 0' . str_repeat(' OR a = 1', 150);
$selectTokens = Tokenizer::filter($tokenizer->tokenize($select));
$reportTokens = Tokenizer::filter($tokenizer->tokenize($report));
$wideTokens = Tokenizer::filter($tokenizer->tokenize($wide));
$selectAst = $parser->parse($selectTokens);
$reportAst = $parser->parse($reportTokens);
$wideAst = $parser->parse($wideTokens);

$filter = Query::equal('status', ['active', 'pending']);
$nested = Query::and([Query::equal('status', ['active']), Query::or([Query::greaterThan('age', 18), Query::isNull('name')]), Query::between('time', 1, 2)]);
$json = $filter->toString();
$nestedJson = $nested->toString();
$list = [$filter, $nested, Query::limit(25), Query::offset(50), Query::orderDesc('time'), Query::select(['name', 'age']), Query::cursorAfter(['$id' => 'x']), Query::count('*', 'total')];
$jsons = array_map(fn (Query $query): string => $query->toString(), array_slice($list, 0, 5));

$cases = [
    'mysql COM_QUERY select' => fn () => $mysql->classify("\x00\x00\x00\x00\x03" . $select),
    'mysql COM_STMT_EXECUTE' => fn () => $mysql->classify("\x05\x00\x00\x00\x17\x01\x00\x00\x00"),
    'postgres Q select' => fn () => $postgres->classify('Q' . "\x00\x00\x00\x00" . $select . "\x00"),
    'postgres Q commented update' => fn () => $postgres->classify('Q' . "\x00\x00\x00\x00" . $commented . "\x00"),
    'classifySQL CTE insert' => fn () => $postgres->classifySQL($cte),
    'classifySQL begin' => fn () => $mysql->classifySQL('BEGIN'),
    'extractKeyword' => fn () => $mysql->extractKeyword($commented),
    'mongodb find' => fn () => $mongo->classify($find),
    'mongodb insert in a txn' => fn () => $mongo->classify($insert),
    // What the AST parser does first with every statement it is given.
    'tokenize select (22 tokens)' => fn () => $tokenizer->tokenize($select),
    'tokenize + filter' => fn () => Tokenizer::filter($tokenizer->tokenize($select)),
    'tokenize mysql # comment' => fn () => $mysqlTokenizer->tokenize($commented . ' # trailing'),
    'tokenize postgres operators' => fn () => $postgresTokenizer->tokenize('SELECT a @> b, c <-> $1, d ?| e FROM "t" WHERE f <=> g'),
    'tokenize CTE (60 tokens)' => fn () => $tokenizer->tokenize($cte),

    'AST parse select (' . count($selectTokens) . ' tokens)' => fn () => $parser->parse($selectTokens),
    'AST parse report (' . count($reportTokens) . ' tokens)' => fn () => $parser->parse($reportTokens),
    'AST parse 150 ORs (' . count($wideTokens) . ' tokens)' => fn () => $parser->parse($wideTokens),
    'AST serialize select' => fn () => $serializer->serialize($selectAst),
    'AST serialize report' => fn () => $postgresSerializer->serialize($reportAst),
    'AST serialize 150 ORs' => fn () => $serializer->serialize($wideAst),
    'AST sql to sql (report)' => fn () => $postgresSerializer->serialize($parser->parse(Tokenizer::filter($tokenizer->tokenize($report)))),
    'AST Grammar::isType' => fn () => Utopia\Query\AST\Grammar::isType('decimal(10, 2)'),
    // What a request does with the queries it is given: parse them, read them, group them.
    'Query::equal' => fn () => Query::equal('status', ['active', 'pending']),
    'new Query' => fn () => new Query(Method::Equal, 'status', ['active']),
    'getters' => fn () => [$filter->getMethod(), $filter->getAttribute(), $filter->getValues(), $filter->getAlias()],
    'parse filter' => fn () => Query::parse($json),
    'parse nested (and of 3)' => fn () => Query::parse($nestedJson),
    'parseQueries (5)' => fn () => Query::parseQueries($jsons),
    'toString' => fn () => $filter->toString(),
    'toString nested' => fn () => $nested->toString(),
    'clone nested' => fn () => clone $nested,
    'groupByType (8)' => fn () => Query::groupByType($list),
    'getByType' => fn () => Query::getByType($list, [Method::Limit, Method::Offset]),
    'fingerprint (8)' => fn () => Query::fingerprint($list),
    'validate (8)' => fn () => Query::validate($list, ['status', 'age', 'name', 'time']),
];
$n = 50000;
$out = [];
foreach ($cases as $name => $fn) {
    for ($i = 0; $i < 1000; $i++) {
        $fn();
    }
    $runs = [];
    for ($r = 0; $r < 5; $r++) {
        $t = hrtime(true);
        for ($i = 0; $i < $n; $i++) {
            $fn();
        }
        $runs[] = (hrtime(true) - $t) / $n;
    }
    sort($runs);
    $out[$name] = $runs[2];
}
$out['peak memory MB'] = round(memory_get_peak_usage(true) / 1048576, 1);
echo json_encode($out), "\n";
