<?php

// The DSN getters, whose handlers are written by hand (src/classes/retarget.rs): through
// PHP subclasses, with extra arguments, on an object built without its constructor, and
// shared many times over. The output must match dsn-getters.expected.

use Utopia\DSN\DSN;

class Sub extends DSN
{
}
class Over extends DSN
{
    public function getHost(): string
    {
        return 'over:' . parent::getHost();
    }
}

$getters = ['getScheme', 'getUser', 'getPassword', 'getHost', 'getPort', 'getPath', 'getQuery'];
$out = function (string $case, callable $f) {
    try {
        $r = $f();
        echo $case, ' => ', json_encode($r), "\n";
    } catch (\Throwable $e) {
        echo $case, ' !! ', get_class($e), ': ', $e->getMessage(), "\n";
    }
};

$dsns = [
    'full' => 'mysql://user:pa%40ss@db.example.com:3306/app?charset=utf8&x=1',
    'bare' => 'redis://cache',
    'nopass' => 'mariadb://root@localhost/x',
];
foreach ($dsns as $label => $raw) {
    foreach ([DSN::class, Sub::class, Over::class] as $class) {
        $d = new $class($raw);
        foreach ($getters as $g) {
            $out("$label $class::$g", fn () => $d->$g());
        }
        $c = clone $d;
        $out("$label $class clone", fn () => [$c->getHost(), $c->getPort(), $c->getQuery()]);
    }
}

$d = new DSN($dsns['full']);
$out('extra arg', fn () => $d->getHost(1));
$out('two extra args', fn () => $d->getPort(1, 2));
$out('reflection invoke', fn () => (new ReflectionMethod(DSN::class, 'getUser'))->invoke($d));
$out('first-class callable', fn () => ($d->getScheme(...))());
$out('array_map', fn () => array_map(fn ($g) => $d->$g(), $getters));
$out('no constructor', fn () => (new ReflectionClass(DSN::class))->newInstanceWithoutConstructor()->getHost());
$out('sub no constructor', fn () => (new ReflectionClass(Sub::class))->newInstanceWithoutConstructor()->getScheme());
$out('static call', fn () => DSN::getHost());

// The same string many times over, then dropped: refcounts must balance.
$held = [];
for ($i = 0; $i < 1000; $i++) {
    $held[] = $d->getHost();
    $held[] = $d->getPassword();
}
$out('held', fn () => [count($held), $held[0], $held[1999]]);
unset($held, $d);
gc_collect_cycles();
$out('after free', fn () => (new DSN($dsns['bare']))->getHost());
$out('memory balanced', function () use ($dsns) {
    $d = new DSN($dsns['full']);
    $d->getHost();
    $before = memory_get_usage();
    for ($i = 0; $i < 10000; $i++) {
        $d->getHost();
        $d->getUser();
        $d->getQuery();
    }
    return memory_get_usage() - $before < 4096;
});
