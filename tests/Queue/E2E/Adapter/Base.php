<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use function Co\run;

use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Publisher\Synchronous;
use Utopia\Queue\Queue;

abstract class Base extends TestCase
{
    protected array $payloads;

    public function setUp(): void
    {
        $this->payloads = [];
        $this->payloads[] = [
            'type' => 'test_string',
            'value' => 'lorem ipsum',
        ];
        $this->payloads[] = [
            'type' => 'test_number',
            'value' => 123,
        ];
        $this->payloads[] = [
            'type' => 'test_number',
            'value' => 123.456,
        ];
        $this->payloads[] = [
            'type' => 'test_bool',
            'value' => true,
        ];
        $this->payloads[] = [
            'type' => 'test_null',
            'value' => null,
        ];
        $this->payloads[] = [
            'type' => 'test_array',
            'value' => [
                1,
                2,
                3,
            ],
        ];
        $this->payloads[] = [
            'type' => 'test_assoc',
            'value' => [
                'string' => 'ipsum',
                'number' => 123,
                'bool' => true,
                'null' => null,
            ],
        ];
    }

    abstract protected function getPublisher(): Synchronous;

    abstract protected function getQueue(): Queue;

    public function testEvents(): void
    {
        $publisher = $this->getPublisher();

        foreach ($this->payloads as $payload) {
            $this->assertTrue($publisher->publish($this->getQueue(), $payload));
        }

        sleep(1);
    }

    public function testConcurrency(): void
    {
        run(function (): void {
            $publisher = $this->getPublisher();
            go(function () use ($publisher): void {
                foreach ($this->payloads as $payload) {
                    $this->assertTrue($publisher->publish($this->getQueue(), $payload));
                }

                sleep(1);
            });
        });
    }

    public function testEnqueuePriority(): void
    {
        $publisher = $this->getPublisher();

        $result = $publisher->publish($this->getQueue(), ['type' => 'test_string', 'value' => 'priority'], priority: true);

        $this->assertTrue($result);
    }

    public function testParamAliases(): void
    {
        $publisher = $this->getPublisher();

        // Resolves via canonical key
        $this->assertTrue($publisher->publish($this->getQueue(), [
            'type' => 'test_alias',
            'aliasValue' => 'canonical',
            'value' => 'canonical',
        ]));

        // Resolves via first alias when canonical absent
        $this->assertTrue($publisher->publish($this->getQueue(), [
            'type' => 'test_alias',
            'alias_value' => 'first-alias',
            'value' => 'first-alias',
        ]));

        // Falls through to later alias when earlier ones absent
        $this->assertTrue($publisher->publish($this->getQueue(), [
            'type' => 'test_alias',
            'aliased' => 'second-alias',
            'value' => 'second-alias',
        ]));

        // Canonical key wins when both canonical and aliases are present
        $this->assertTrue($publisher->publish($this->getQueue(), [
            'type' => 'test_alias',
            'aliasValue' => 'canonical-wins',
            'alias_value' => 'should-lose',
            'aliased' => 'should-lose',
            'value' => 'canonical-wins',
        ]));

        sleep(1);
    }

    #[Depends('testEvents')]
    public function testRetry(): void
    {
        $publisher = $this->getPublisher();

        $published = $publisher->publish($this->getQueue(), [
            'type' => 'test_exception',
            'id' => 1,
        ]);

        $this->assertTrue($published);

        $published = $publisher->publish($this->getQueue(), [
            'type' => 'test_exception',
            'id' => 2,
        ]);

        $this->assertTrue($published);

        $published = $publisher->publish($this->getQueue(), [
            'type' => 'test_exception',
            'id' => 3,
        ]);

        $this->assertTrue($published);

        $published = $publisher->publish($this->getQueue(), [
            'type' => 'test_exception',
            'id' => 4,
        ]);

        $this->assertTrue($published);

        sleep(1);
        $publisher->retry($this->getQueue());
        sleep(1);
        $publisher->retry($this->getQueue(), 2);
        sleep(1);
    }
}
