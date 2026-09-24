<?php

declare(strict_types=1);

namespace Utopia\Tests\Scope;

trait EmptyObjectFidelity
{
    public function testEmptyObjectFidelity(): void
    {
        $key = 'empty-object-fidelity';
        $data = [
            'empty' => new \stdClass(),
            'nested' => ['empty' => new \stdClass()],
            'list' => [new \stdClass(), ['x' => 1]],
            'emptyArray' => [],
        ];

        $this->assertSame($data, self::$cache->save($key, $data, $key));
        $this->assertSame(
            '{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}',
            json_encode(self::$cache->load($key, 60, $key)),
        );
        $this->assertTrue(self::$cache->touch($key, $key));
        $this->assertSame(
            '{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}',
            json_encode(self::$cache->load($key, 60, $key)),
        );

        self::$cache->purge($key);
    }
}
