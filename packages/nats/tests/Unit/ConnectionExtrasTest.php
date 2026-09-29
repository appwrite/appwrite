<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\AuthenticationException;
use Utopia\NATS\Exception\MaxPayloadException;
use Utopia\NATS\Exception\PermissionException;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;

/**
 * Connection-level parity extras: the ADR-7 server-error mapping and the ADR-5
 * lame-duck-mode async-INFO path, both driven without a live server.
 */
final class ConnectionExtrasTest extends TestCase
{
    public function testAuthorizationViolationMapsToAuthenticationException(): void
    {
        $this->assertInstanceOf(
            AuthenticationException::class,
            Connection::mapServerError('Authorization Violation'),
        );
    }

    public function testUserAuthenticationExpiredMapsToAuthenticationException(): void
    {
        $this->assertInstanceOf(
            AuthenticationException::class,
            Connection::mapServerError('User Authentication Expired'),
        );
    }

    public function testMaximumPayloadMapsToMaxPayloadException(): void
    {
        $this->assertInstanceOf(
            MaxPayloadException::class,
            Connection::mapServerError('Maximum Payload Exceeded'),
        );
    }

    public function testPermissionsViolationForSubscriptionMapsToPermissionException(): void
    {
        $this->assertInstanceOf(
            PermissionException::class,
            Connection::mapServerError("Permissions Violation for Subscription to 'foo.bar'"),
        );
    }

    public function testPermissionsViolationForPublishMapsToPermissionException(): void
    {
        $this->assertInstanceOf(
            PermissionException::class,
            Connection::mapServerError("Permissions Violation for Publish to 'foo.bar'"),
        );
    }

    public function testUnknownErrorMapsToProtocolException(): void
    {
        $this->assertInstanceOf(
            ProtocolException::class,
            Connection::mapServerError('some unexpected error'),
        );
    }

    public function testLameDuckInfoInvokesCallback(): void
    {
        $fired = false;
        $fake = new FakeTransport();
        $conn = Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            onLameDuck: function () use (&$fired): void {
                $fired = true;
            },
            transportFactory: fn(string $scheme): FakeTransport => $fake,
        ));

        // Server signals lame-duck mode via an asynchronous INFO. With only one
        // known server there is nowhere to fail over, so the callback fires and
        // the connection is left intact.
        $fake->pushInbound('INFO {"server_id":"FAKE","ldm":true}' . "\r\n");
        $conn->processMessage(1.0);

        $this->assertTrue($fired);
        $this->assertFalse($conn->isReconnecting());

        $conn->close();
    }

    public function testNonLameDuckInfoDoesNotInvokeCallback(): void
    {
        $fired = false;
        $fake = new FakeTransport();
        $conn = Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            onLameDuck: function () use (&$fired): void {
                $fired = true;
            },
            transportFactory: fn(string $scheme): FakeTransport => $fake,
        ));

        $fake->pushInbound('INFO {"server_id":"FAKE","ldm":false}' . "\r\n");
        $conn->processMessage(1.0);

        $this->assertFalse($fired);

        $conn->close();
    }
}
