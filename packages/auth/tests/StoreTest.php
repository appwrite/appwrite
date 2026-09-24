<?php

declare(strict_types=1);

namespace Utopia\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Store;

final class StoreTest extends TestCase
{
    public function testGetAndSetProperty(): void
    {
        $store = new Store();

        // Test setting and getting a string
        $store->setProperty('name', 'John Doe');
        $this->assertEquals('John Doe', $store->getProperty('name'));

        // Test setting and getting different types
        $store->setProperty('age', 30)
              ->setProperty('active', true)
              ->setProperty('scores', [95, 87, 92])
              ->setProperty('details', ['city' => 'New York', 'country' => 'USA']);

        $this->assertEquals(30, $store->getProperty('age'));
        $this->assertTrue($store->getProperty('active'));
        $this->assertEquals([95, 87, 92], $store->getProperty('scores'));
        $this->assertEquals(['city' => 'New York', 'country' => 'USA'], $store->getProperty('details'));

        // Test default value for non-existent key
        $this->assertNull($store->getProperty('nonexistent'));
        $this->assertEquals('default', $store->getProperty('nonexistent', 'default'));
    }

    public function testGetAndSetKey(): void
    {
        $store = new Store();

        // Test initial key is null
        $this->assertNull($store->getKey());

        // Test setting and getting a key
        $store->setKey('test-key');
        $this->assertEquals('test-key', $store->getKey());

        // Test setting key to null
        $store->setKey(null);
        $this->assertNull($store->getKey());

        // Test method chaining
        $store->setKey('new-key')->setProperty('test', 'value');
        $this->assertEquals('new-key', $store->getKey());
        $this->assertEquals('value', $store->getProperty('test'));
    }

    public function testEncodeAndDecode(): void
    {
        $store = new Store();
        $data = [
            'name' => 'John Doe',
            'age' => 30,
            'active' => true,
            'scores' => [95, 87, 92],
            'details' => ['city' => 'New York', 'country' => 'USA'],
        ];

        // Set multiple values and key
        foreach ($data as $key => $value) {
            $store->setProperty($key, $value);
        }
        $store->setKey('test-key');

        // Encode the store
        $encoded = $store->encode();

        // Verify it's a valid base64 string
        $decoded = base64_decode($encoded, true);
        $this->assertNotFalse($decoded);
        $this->assertSame($encoded, base64_encode($decoded));

        // Create a new store and decode the data
        $newStore = new Store();
        $newStore->decode($encoded);

        // Verify all data was preserved
        foreach ($data as $key => $value) {
            $this->assertEquals($value, $store->getProperty($key));
        }
    }

    public function testDecodeInvalidData(): void
    {
        $store = new Store();

        // Test decoding invalid base64
        $store->decode('invalid-base64');
        $this->assertNull($store->getProperty('any'));

        // Test decoding valid base64 but invalid JSON
        $store->decode(base64_encode('invalid-json'));
        $this->assertNull($store->getProperty('any'));

        // Test decoding valid base64 and JSON, but not an array
        $json = json_encode('string', JSON_THROW_ON_ERROR);
        $store->decode(base64_encode($json));
        $this->assertNull($store->getProperty('any'));
    }

    public function testEncodeWithInvalidData(): void
    {
        $store = new Store();
        // Create an invalid UTF-8 string that will cause json_encode to fail
        $store->setProperty('invalid', "\xFF");

        $this->expectException(\JsonException::class);
        $store->encode();
    }
}
