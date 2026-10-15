<?php

// Generates redis_operators_php.json: each case [current, method, values]
// with what the Redis adapter's applyOperator() returns for it in PHP 8.5
// (utopia-php/database 8.1.3): `var_export()` of the result, or the class and
// message of what it throws.
//
//   docker cp redis_operators_php.php appwrite:/tmp/ && docker exec appwrite php -d display_errors=0 /tmp/redis_operators_php.php

require '/usr/src/code/vendor/autoload.php';

use Utopia\Database\Adapter\Redis as RedisAdapter;
use Utopia\Database\Operator;
use Utopia\Database\OperatorType;

$cases = [
    [5, 'increment', [3]], [5.5, 'increment', [2]], ['7', 'increment', [1]], [null, 'increment', [2]],
    [10, 'increment', [5, 12]], [10, 'increment', [1.5]], [9223372036854775807, 'increment', [1]],
    ['abc', 'increment', [1]], [true, 'increment', [1]], [10, 'increment', [2, '11']], [1, 'increment', ['2']],
    [5, 'decrement', [3, 4]], [5, 'decrement', [10]], [-9223372036854775807, 'decrement', [2]], [3.5, 'decrement', [0.5]],
    [3, 'multiply', [2.5]], [4, 'multiply', [0.5]], [3, 'multiply', [3, 5]], [9223372036854775807, 'multiply', [2]],
    [1.5, 'multiply', ['2.5']], [2, 'multiply', [1.0e300]],
    [10, 'divide', [4]], [10, 'divide', [5]], [10, 'divide', [0]], [10, 'divide', ['0.0']], [10, 'divide', ['x']],
    [7, 'divide', [2, 4]], [7.5, 'divide', [2.5]], [-9223372036854775807, 'divide', [-1]],
    [10, 'modulo', [3]], [-10, 'modulo', [3]], [10, 'modulo', [0.5]], [10.9, 'modulo', [3]], ['x', 'modulo', [3]],
    [10, 'modulo', ['4']], [7, 'modulo', [-1]],
    [2, 'power', [10]], [2, 'power', [62]], [2, 'power', [64]], [2, 'power', [-1]], [0, 'power', [-1, 5]],
    [-8, 'power', [0.5, 100]], [3, 'power', [2.0]], [2, 'power', [0.5]], [10, 'power', [400, 1.0e300]],
    [-2, 'power', [3]], [3, 'power', ['2']], [1.5, 'power', [2]], [7, 'power', [3, 300]],
    ['a', 'stringConcat', ['b']], [null, 'stringConcat', ['b']], [5, 'stringConcat', ['b']], ['a', 'stringConcat', [5]],
    ['aaa', 'stringReplace', ['a', 'b']], [null, 'stringReplace', ['a', 'b']], ['abc', 'stringReplace', ['', 'x']],
    ['abcabc', 'stringReplace', ['bc', '']],
    [true, 'toggle', []], [null, 'toggle', []], ['0', 'toggle', []], [[1], 'toggle', []],
    [[1], 'arrayAppend', [2, 3]], ['[1,2]', 'arrayAppend', [3]], [null, 'arrayAppend', ['x']], ['{"a":1}', 'arrayAppend', [2]],
    ['nope', 'arrayAppend', [1]],
    [[1], 'arrayPrepend', [2, 3]], [['k' => 1, 'j' => 2], 'arrayPrepend', [0]],
    [[1, 2, 3], 'arrayInsert', [1, 'x']], [[1], 'arrayInsert', [9, 'y']], [[1], 'arrayInsert', [-3, 'y']],
    [[1], 'arrayInsert', ['1', 'z']], [[1, 2], 'arrayInsert', [1]],
    [[1, '1', 2], 'arrayRemove', [1]], [[1, 2, null], 'arrayRemove', []],
    [[1, '1', 2, 2.0, 'a', 'A', [1], [1]], 'arrayUnique', []],
    [[1, 2, 3, '4', 'x'], 'arrayIntersect', [2, 4]], [[1, 2, 3, '4', 'x', 0], 'arrayDiff', [2, 4, 'y']],
    [[1, 5, 10, null, '7', 'z'], 'arrayFilter', ['greaterThan', 4]], [[1, null, 0, ''], 'arrayFilter', ['isNull']],
    [[1, null], 'arrayFilter', ['isNotNull']], [[1, '1', 2, true], 'arrayFilter', ['equal', '1']],
    [[1, '1', 2], 'arrayFilter', ['notEqual', 1]], [[1, 2], 'arrayFilter', ['bogus', 1]],
    [[1, 2, 3], 'arrayFilter', ['lessThanEqual', 2]], [[1, 2, 3], 'arrayFilter', ['greaterThanEqual', '2']],
    [[1, 2, 3], 'arrayFilter', ['lessThan', 2.5]],
    ['2026-01-01 00:00:00.000', 'dateAddDays', [2]], [null, 'dateAddDays', [1]], ['not-a-date', 'dateAddDays', [1]],
    ['2026-03-01', 'dateAddDays', [-1]], ['2026-01-01T10:00:00+02:00', 'dateAddDays', [1]],
    ['2026-01-01 00:00:00.000', 'dateSubDays', [2]], ['2024-03-01 12:30:45.123', 'dateSubDays', ['1']],
];

$adapter = new class (new Redis()) extends RedisAdapter {
    public function apply(mixed $current, Operator $operator): mixed
    {
        return $this->applyOperator($current, $operator);
    }
};

$out = [];
foreach ($cases as [$current, $method, $values]) {
    try {
        $result = ['ok', var_export($adapter->apply($current, new Operator(OperatorType::from($method), 'value', $values)), true)];
    } catch (\Throwable $e) {
        $result = ['error', $e::class, $e->getMessage()];
    }
    $out[] = ['current' => $current, 'method' => $method, 'values' => $values, 'result' => $result];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), "\n";
