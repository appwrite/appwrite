<?php

declare(strict_types=1);

namespace Utopia\Client\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Client\Client;
use Utopia\Client\Psr18\StreamingClientInterface;

final class CompatTest extends TestCase
{
    public function testOldClientNameResolvesToTheMovedClass(): void
    {
        $this->assertTrue(class_exists('Utopia\Client'));
        $this->assertSame(Client::class, new \ReflectionClass('Utopia\Client')->getName());
    }

    public function testOldStreamingInterfaceNameResolvesToTheMovedInterface(): void
    {
        $this->assertTrue(interface_exists('Utopia\Psr18\StreamingClientInterface'));
        $this->assertSame(StreamingClientInterface::class, new \ReflectionClass('Utopia\Psr18\StreamingClientInterface')->getName());
    }
}
