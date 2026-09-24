<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hash;

final class HashTest extends TestCase
{
    protected Hash $hash;

    protected function setUp(): void
    {
        // Create a concrete implementation of Hash for testing
        $this->hash = new class extends Hash {
            public function hash(string $value): string
            {
                return 'hashed_' . $value;
            }

            public function verify(string $value, string $hash): bool
            {
                return $hash === 'hashed_' . $value;
            }

            public function getName(): string
            {
                return 'test_hash';
            }
        };
    }

    public function testSetAndGetOption(): void
    {
        $this->hash->setOption('key1', 'value1');
        $this->assertEquals('value1', $this->hash->getOption('key1'));
        $this->assertNull($this->hash->getOption('nonexistent'));
        $this->assertEquals('default', $this->hash->getOption('nonexistent', 'default'));
    }

    public function testSetOptions(): void
    {
        $options = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => ['nested' => 'value'],
        ];

        $this->hash->setOptions($options);

        // Verify all options were set
        $this->assertSame($options, $this->hash->getOptions());

        // Verify individual options
        foreach ($options as $key => $value) {
            $this->assertEquals($value, $this->hash->getOption($key));
        }
    }

    public function testGetOptions(): void
    {
        $options = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        $this->hash->setOptions($options);
        $this->assertSame($options, $this->hash->getOptions());
    }

    public function testMethodChaining(): void
    {
        $result = $this->hash
            ->setOption('key1', 'value1')
            ->setOptions(['key2' => 'value2']);

        $this->assertInstanceOf(Hash::class, $result);
        $this->assertEquals('value1', $this->hash->getOption('key1'));
        $this->assertEquals('value2', $this->hash->getOption('key2'));
    }
}
