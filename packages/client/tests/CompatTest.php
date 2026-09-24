<?php

declare(strict_types=1);

namespace Utopia\Client\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Utopia\Client\Adapter\Curl\Client as CurlClient;
use Utopia\Client\Client;
use Utopia\Client\Psr18\StreamingClientInterface;

final class CompatTest extends TestCase
{
    public function testOldClientNameResolvesToTheMovedClass(): void
    {
        $old = \Utopia\Client::class;
        if (!class_exists($old)) {
            $this->fail($old . ' does not resolve');
        }

        $this->assertSame(Client::class, new \ReflectionClass($old)->getName());
    }

    public function testOldStreamingInterfaceNameResolvesToTheMovedInterface(): void
    {
        $old = \Utopia\Psr18\StreamingClientInterface::class;
        if (!interface_exists($old)) {
            $this->fail($old . ' does not resolve');
        }

        $this->assertSame(StreamingClientInterface::class, new \ReflectionClass($old)->getName());
    }

    /**
     * PHP never autoloads a name while checking a declared type, so a
     * consumer typed against an old name accepts the moved classes only if
     * the alias exists before any code mentions it. A fresh process keeps
     * the earlier tests from registering it first.
     */
    #[RunInSeparateProcess]
    public function testOldNamesInTypeDeclarationsAcceptTheMovedClasses(): void
    {
        $consumer = new OldNamesConsumer(new Client(new CurlClient()));

        $this->assertInstanceOf(Client::class, $consumer->client);
        $this->assertInstanceOf(StreamingClientInterface::class, $consumer->stream);
    }
}
