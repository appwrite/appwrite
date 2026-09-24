<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

use Swoole\Coroutine\WaitGroup;
use Utopia\Cache\Adapter\Redis\Multiplexing as RedisMultiplexing;
use Utopia\Cache\Cache;
use Utopia\Tests\Services;

final class MultiplexingTest extends TestCase
{
    protected string $key = 'test-key-for-cache';

    protected string $data = 'test data string';

    /** @var array<string> */
    protected array $dataArray = ['test', 'data', 'string'];

    /**
     * Run the entire test body inside a Swoole coroutine context.
     */
    private function runCo(callable $fn): void
    {
        $error = null;
        run(function () use ($fn, &$error): void {
            try {
                $fn();
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                $this->close();
            }
        });
        if ($error instanceof \Throwable) {
            throw $error;
        }
    }

    private RedisMultiplexing $adapter;

    private function makeCache(): Cache
    {
        $this->adapter = new RedisMultiplexing(Services::HOST, Services::REDIS_PORT);

        return new Cache($this->adapter);
    }

    private function close(): void
    {
        if (isset($this->adapter)) {
            $this->adapter->disconnect();
        }
    }

    public function testCacheSave(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $result = $cache->save($this->key, $this->dataArray, $this->key);
            $this->assertEquals($this->dataArray, $result);

            $result = $cache->save($this->key, $this->data, $this->key);
            $this->assertSame($this->data, $result);

            $loaded = $cache->load($this->key, 60, $this->key);
            $this->assertEquals($this->data, $loaded);
        });
    }

    public function testCachePurge(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->save($this->key, $this->data, $this->key);

            $loaded = $cache->load($this->key, 60, $this->key);
            $this->assertEquals($this->data, $loaded);

            $this->assertTrue($cache->purge($this->key));
            $this->assertFalse($cache->load($this->key, 60, $this->key));
        });
    }

    public function testCacheTouch(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->save('touch-key', 'touch data', 'touch-key');

            sleep(3);

            $this->assertFalse($cache->load('touch-key', 2, 'touch-key'));
            $this->assertTrue($cache->touch('touch-key', 'touch-key'));
            $this->assertSame('touch data', $cache->load('touch-key', 2, 'touch-key'));
            $this->assertFalse($cache->touch('missing-touch-key', 'missing-touch-key'));

            $cache->purge('touch-key');
        });
    }

    public function testPing(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $this->assertTrue($cache->ping());
        });
    }

    public function testFlush(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->save('x', 'x', 'x');
            $cache->save('y', 'y', 'y');

            $this->assertEquals('x', $cache->load('x', 100, 'x'));
            $this->assertEquals('y', $cache->load('y', 100, 'y'));

            $this->assertTrue($cache->flush());
            $this->assertFalse($cache->load('x', 100, 'x'));
            $this->assertFalse($cache->load('y', 100, 'y'));
        });
    }

    public function testGetSize(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $cache->save('test:file33', 'file33', 'test:file33');
            $cache->save('test:file34', 'file34', 'test:file34');
            $cache->save('test:file35', 'file35', 'test:file35');

            $this->assertSame(3, $cache->getSize());
        });
    }

    public function testJsonData(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $payload = [
                'id' => 'doc-42',
                'title' => 'Hello, "world"',
                'tags' => ['php', 'redis', 'swoole'],
                'meta' => [
                    'count' => 7,
                    'ratio' => 0.125,
                    'flag' => true,
                    'missing' => null,
                ],
                'unicode' => 'café — 日本語 — 🚀',
                'nested' => [
                    'a' => ['b' => ['c' => ['d' => 'deep']]],
                ],
                'binary-ish' => "line1\nline2\twith\ttabs\r\nand crlf",
            ];

            $saved = $cache->save('json:doc', $payload, 'json:doc');
            $this->assertEquals($payload, $saved);

            $loaded = $cache->load('json:doc', 60, 'json:doc');
            $this->assertEquals($payload, $loaded);

            $jsonString = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->assertNotFalse($jsonString);
            $this->assertSame($jsonString, $cache->save('json:string', $jsonString, 'json:string'));
            $this->assertEquals($jsonString, $cache->load('json:string', 60, 'json:string'));
        });
    }

    public function testEmptyObjectFidelity(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $data = [
                'empty' => new \stdClass(),
                'nested' => ['empty' => new \stdClass()],
                'list' => [new \stdClass(), ['x' => 1]],
                'emptyArray' => [],
            ];

            $this->assertSame($data, $cache->save('empty-object-fidelity', $data, 'empty-object-fidelity'));
            $this->assertSame(
                '{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}',
                json_encode($cache->load('empty-object-fidelity', 60, 'empty-object-fidelity')),
            );
            $this->assertTrue($cache->touch('empty-object-fidelity', 'empty-object-fidelity'));
            $this->assertSame(
                '{"empty":{},"nested":{"empty":{}},"list":[{},{"x":1}],"emptyArray":[]}',
                json_encode($cache->load('empty-object-fidelity', 60, 'empty-object-fidelity')),
            );

            $cache->purge('empty-object-fidelity');
        });
    }

    public function testLargeJsonPayload(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $rows = [];
            for ($i = 0; $i < 1000; $i++) {
                $rows[] = [
                    'i' => $i,
                    'name' => 'row-' . $i,
                    'value' => str_repeat('x', 64),
                ];
            }
            $payload = ['rows' => $rows];

            $saved = $cache->save('json:large', $payload, 'json:large');
            $this->assertEquals($payload, $saved);

            $loaded = $cache->load('json:large', 60, 'json:large');
            $this->assertEquals($payload, $loaded);
        });
    }

    public function testConcurrentJsonMultiplexing(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $count = 50;
            $wg = new WaitGroup();
            $errors = [];

            for ($i = 0; $i < $count; $i++) {
                $wg->add();
                Coroutine::create(function () use ($cache, $i, $wg, &$errors): void {
                    try {
                        $key = 'json-mux:' . $i;
                        $value = [
                            'i' => $i,
                            'tags' => ['a', 'b', 'c-' . $i],
                            'meta' => ['nested' => ['count' => $i * 2]],
                            'text' => 'café 🚀 row ' . $i,
                        ];
                        $saved = $cache->save($key, $value, $key);
                        if ($saved !== $value) {
                            $errors[] = "save mismatch for $i";
                        }
                        $loaded = $cache->load($key, 60, $key);
                        if ($loaded != $value) {
                            $errors[] = "load mismatch for $i: " . var_export($loaded, true);
                        }
                    } catch (\Throwable $th) {
                        $errors[] = $th->getMessage();
                    } finally {
                        $wg->done();
                    }
                });
            }

            $wg->wait();

            $this->assertEmpty($errors, 'Concurrent errors: ' . implode('; ', $errors));
            $this->assertSame($count, $cache->getSize());
        });
    }

    /**
     * Concurrent multiplexing: many coroutines hitting the same connection.
     */
    public function testConcurrentMultiplexing(): void
    {
        $this->runCo(function (): void {
            $cache = $this->makeCache();
            $cache->flush();

            $count = 50;
            $wg = new WaitGroup();
            $errors = [];

            for ($i = 0; $i < $count; $i++) {
                $wg->add();
                Coroutine::create(function () use ($cache, $i, $wg, &$errors): void {
                    try {
                        $key = 'mux:' . $i;
                        $value = 'value-' . $i;
                        $saved = $cache->save($key, $value, $key);
                        if ($saved !== $value) {
                            $errors[] = "save mismatch for $i: " . var_export($saved, true);
                        }
                        $loaded = $cache->load($key, 60, $key);
                        if ($loaded !== $value) {
                            $errors[] = "load mismatch for $i: " . var_export($loaded, true);
                        }
                    } catch (\Throwable $th) {
                        $errors[] = $th->getMessage();
                    } finally {
                        $wg->done();
                    }
                });
            }

            $wg->wait();

            $this->assertEmpty($errors, 'Concurrent errors: ' . implode('; ', $errors));
            $this->assertSame($count, $cache->getSize());
        });
    }
}
