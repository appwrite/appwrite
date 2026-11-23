<?php

// Builders that yield in the middle of a build, many at once on one thread: an override, a
// beforeBuild() callback and an afterBuild() callback each sleep, so another coroutine builds
// before this one resumes. Each statement must be the one its own builder makes alone. run.sh
// diffs the output of the PHP classes against the extension's
// (_APP_EXTENSION_LIBRARIES=query).

require __DIR__ . '/../../../vendor/autoload.php';

use Swoole\Coroutine;
use Utopia\Query\Builder\MySQL;
use Utopia\Query\Builder\PostgreSQL;
use Utopia\Query\Builder\Statement;
use Utopia\Query\Query;

class YieldsInMySQL extends MySQL
{
    protected function resolveAttribute(string $attribute): string
    {
        Coroutine::sleep(0.001);

        return 'a_' . $attribute;
    }
}

class YieldsInPostgreSQL extends PostgreSQL
{
    protected function resolveAttribute(string $attribute): string
    {
        Coroutine::sleep(0.002);

        return 'b_' . $attribute;
    }
}

class YieldsNowhere extends MySQL
{
}

function made(string $class, int $number): MySQL|PostgreSQL
{
    $builder = (new $class())->from('t' . $number)->select(['a', 'b'])
        ->filter([Query::equal('x', [$number]), Query::greaterThan('y', $number)])
        ->sortAsc('a')
        ->beforeBuild(function ($builder) use ($number) {
            Coroutine::sleep(0.001);
            $builder->limit($number + 1);
        })
        ->afterBuild(function (Statement $statement) use ($class, $number) {
            Coroutine::sleep(0.001);

            return new Statement($statement->query . ' /* ' . $class . ' ' . $number . ' */', $statement->bindings);
        });

    // A clone is built by its own object, with the callbacks of the one it was cloned from.
    return $number % 2 ? clone $builder : $builder;
}

$classes = [YieldsInMySQL::class, YieldsInPostgreSQL::class, YieldsNowhere::class];
$alone = [];
$together = [];
Coroutine\run(function () use ($classes, &$alone, &$together) {
    foreach ($classes as $class) {
        for ($number = 0; $number < 12; $number++) {
            $statement = made($class, $number)->build();
            $alone[$class . ' ' . $number] = [$statement->query, $statement->bindings];
        }
    }
    $group = new Coroutine\WaitGroup();
    for ($round = 0; $round < 20; $round++) {
        foreach ($classes as $class) {
            for ($number = 0; $number < 12; $number++) {
                $group->add();
                Coroutine::create(function () use ($class, $number, $round, $group, &$together) {
                    try {
                        $statement = made($class, $number)->build();
                        $together[$round][$class . ' ' . $number] = [$statement->query, $statement->bindings];
                    } catch (Throwable $thrown) {
                        $together[$round][$class . ' ' . $number] = [$thrown::class, $thrown->getMessage()];
                    }
                    $group->done();
                });
            }
        }
    }
    $group->wait();
});

$crossed = 0;
foreach ($together as $round) {
    foreach ($round as $name => $built) {
        if ($built !== $alone[$name]) {
            $crossed++;
            echo json_encode(['crossed' => $name, 'alone' => $alone[$name], 'together' => $built]) . PHP_EOL;
        }
    }
}
foreach ($alone as $name => $built) {
    echo json_encode([$name, $built]) . PHP_EOL;
}
echo json_encode(['built' => array_sum(array_map(count(...), $together)), 'crossed' => $crossed]) . PHP_EOL;
exit($crossed ? 1 : 0);
