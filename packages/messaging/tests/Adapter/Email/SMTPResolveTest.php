<?php

declare(strict_types=1);

namespace Utopia\Messaging\Tests\Adapter\Email;

use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Adapter\Email\SMTP\NoHostAnswered;

/**
 * Where a host is allowed to be dialled is the caller's rule, handed in as a
 * resolver. These tests are about what the adapter does with its answer: no host
 * is dialled before it is checked, and the one that is dialled is the address
 * the check returned, not the name it was asked about.
 */
final class SMTPResolveTest extends TestCase
{
    private function dial(SMTP $adapter): void
    {
        new \ReflectionMethod(SMTP::class, 'dial')->invoke($adapter);
    }

    public function testARefusedHostIsNeverDialled(): void
    {
        $asked = [];

        $adapter = new SMTP(
            host: 'internal.example.com',
            port: 25,
            resolve: function (string $host) use (&$asked): string {
                $asked[] = $host;

                throw new \InvalidArgumentException("Hostname {$host} resolves to private or reserved address 10.0.0.1.");
            },
        );

        try {
            $this->dial($adapter);
            $this->fail('A refused host must not be dialled.');
        } catch (NoHostAnswered $exception) {
            $this->assertSame(['internal.example.com'], $asked);
            $this->assertStringContainsString('private or reserved address 10.0.0.1', $exception->getMessage());
            // Nothing about a refused host changes between attempts
            $this->assertTrue($exception->permanent);
        }
    }

    public function testAnAddressLiteralIsCheckedLikeAName(): void
    {
        $asked = [];

        $adapter = new SMTP(
            host: '169.254.169.254',
            port: 25,
            resolve: function (string $host) use (&$asked): string {
                $asked[] = $host;

                throw new \InvalidArgumentException("Address {$host} is in a private or reserved range.");
            },
        );

        try {
            $this->dial($adapter);
            $this->fail('A refused address must not be dialled.');
        } catch (NoHostAnswered $exception) {
            $this->assertSame(['169.254.169.254'], $asked);
        }
    }

    public function testEveryEntryOfAHostListIsCheckedByName(): void
    {
        $asked = [];

        $adapter = new SMTP(
            host: 'tls://smtp1.example.com:587;smtp2.example.com',
            port: 25,
            resolve: function (string $host) use (&$asked): string {
                $asked[] = $host;

                throw new \InvalidArgumentException("Refused {$host}.");
            },
        );

        try {
            $this->dial($adapter);
            $this->fail('A host list of refused entries must not be dialled.');
        } catch (NoHostAnswered $exception) {
            // The scheme and the port are not part of the name the rule is asked about
            $this->assertSame(['smtp1.example.com', 'smtp2.example.com'], $asked);
        }
    }

    public function testARefusedEntryDoesNotStopTheNextOne(): void
    {
        $adapter = new SMTP(
            host: 'internal.example.com:1;smtp.example.com:1',
            port: 25,
            resolve: fn (string $host): string => match ($host) {
                'smtp.example.com' => '127.0.0.1',
                default => throw new \InvalidArgumentException("Refused {$host}."),
            },
        );

        try {
            $this->dial($adapter);
            $this->fail('Nothing listens on port 1.');
        } catch (NoHostAnswered $exception) {
            $this->assertStringContainsString('internal.example.com:1 (Refused internal.example.com.)', $exception->getMessage());
            // The allowed entry was dialled, and on the address the rule returned
            $this->assertStringContainsString('Cannot reach 127.0.0.1:1', $exception->getMessage());
        }
    }

    public function testTheResolvedAddressIsDialledNotTheName(): void
    {
        // A name that resolves nowhere: reaching 127.0.0.1 can only come from the resolver
        $adapter = new SMTP(
            host: 'no-such-host.invalid:1',
            port: 25,
            resolve: fn (string $host): string => '127.0.0.1',
        );

        try {
            $this->dial($adapter);
            $this->fail('Nothing listens on port 1.');
        } catch (NoHostAnswered $exception) {
            $this->assertStringContainsString('Cannot reach 127.0.0.1:1', $exception->getMessage());
            $this->assertStringNotContainsString('Cannot reach no-such-host.invalid', $exception->getMessage());
        }
    }
}
