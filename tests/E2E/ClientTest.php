<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\E2E;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Utopia\SMTP\Address;
use Utopia\SMTP\Auth\Login;
use Utopia\SMTP\Auth\Plain;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Envelope;
use Utopia\SMTP\Exception\AuthenticationException;
use Utopia\SMTP\Exception\TransactionException;
use Utopia\SMTP\Message;
use Utopia\SMTP\Outcome;
use Utopia\SMTP\Tests\E2E\Support\Server;

/**
 * The session: encryption, authentication, recipients and connection reuse,
 * against a server that answers for itself.
 */
final class ClientTest extends Server
{
    private function message(string $subject = 'Hello'): Message
    {
        return new Message(
            from: new Address('jane@example.test', 'Jane Doe'),
            to: [new Address('john@example.test')],
            subject: $subject,
            text: 'Plain body',
        );
    }

    public function testUpgradesThroughStartTlsAndDelivers(): void
    {
        $client = $this->client();
        $result = $client->send($this->message('Hello from Utopia'));
        $client->close();

        $this->assertTrue($result->isComplete());
        $this->assertSame(['john@example.test'], $result->accepted);

        $delivered = $this->delivered();
        $this->assertSame('Hello from Utopia', $this->field($delivered, 'Subject'));
        $this->assertStringContainsString('Plain body', $this->field($delivered, 'Text'));
    }

    public function testAdvertisesTheExtensionsWeRelyOn(): void
    {
        $client = $this->client();
        $capabilities = $client->capabilities();

        $this->assertTrue($capabilities->has('AUTH'));
        $this->assertTrue($capabilities->has('ENHANCEDSTATUSCODES'));
        $this->assertContains('PLAIN', $capabilities->mechanisms());
        $this->assertContains('LOGIN', $capabilities->mechanisms());

        // The server advertises "SIZE 0", which means it sets no fixed maximum.
        $this->assertNull($capabilities->maxSize());

        // STARTTLS is gone from the reply read after the upgrade, which is how
        // we know the capabilities were rebuilt rather than reused.
        $this->assertFalse($capabilities->has('STARTTLS'));

        $client->close();
    }

    public function testSendsWithoutEncryptionWhenAsked(): void
    {
        $client = $this->client(Encryption::None);
        $client->send($this->message('Plaintext'));
        $client->close();

        $this->assertSame('Plaintext', $this->field($this->delivered(), 'Subject'));
    }

    public function testDeliversOverImplicitTls(): void
    {
        // No upgrade to make: the greeting itself arrives encrypted.
        $client = $this->client(Encryption::Implicit, port: self::IMPLICIT_PORT);
        $client->send($this->message('Implicit'));
        $client->close();

        $this->assertSame('Implicit', $this->field($this->delivered(self::IMPLICIT_API), 'Subject'));
    }

    #[RequiresPhpExtension('swoole')]
    public function testUpgradesAndDeliversOverTheCoroutineTransport(): void
    {
        // Swoole performs the STARTTLS upgrade with enableSSL rather than
        // stream_socket_enable_crypto. Only a real handshake settles whether
        // the two transports keep the same promise.
        $this->coroutine(function (): void {
            $client = $this->coroutineClient();
            $client->send($this->message('Coroutine'));
            $client->close();
        });

        $this->assertSame('Coroutine', $this->field($this->delivered(), 'Subject'));
    }

    #[RequiresPhpExtension('swoole')]
    public function testDeliversOverImplicitTlsOnTheCoroutineTransport(): void
    {
        // The other path through the same transport: encrypted from the socket
        // up, with no upgrade to perform.
        $this->coroutine(function (): void {
            $client = $this->coroutineClient(Encryption::Implicit, self::IMPLICIT_PORT);
            $client->send($this->message('Coroutine implicit'));
            $client->close();
        });

        $this->assertSame('Coroutine implicit', $this->field($this->delivered(self::IMPLICIT_API), 'Subject'));
    }

    public function testLoginMechanismAlsoAuthenticates(): void
    {
        $client = $this->client(authenticators: [new Login('jane', 'secret')]);
        $client->send($this->message('Login'));
        $client->close();

        $this->assertSame('Login', $this->field($this->delivered(), 'Subject'));
    }

    public function testRefusesBadCredentials(): void
    {
        $client = $this->client(authenticators: [new Plain('jane', 'wrong')]);

        $this->expectException(AuthenticationException::class);

        $client->send($this->message());
    }

    public function testCarriesSeveralMessagesOnOneConnection(): void
    {
        $client = $this->client();

        foreach (['First', 'Second', 'Third'] as $subject) {
            $client->send($this->message($subject));
        }

        $client->close();

        $this->assertCount(3, $this->messages());
    }

    public function testStartsAFreshSessionAfterClosing(): void
    {
        // One authenticator, two connections. A mechanism that remembered where
        // it got to in the first exchange would answer the wrong prompt in the
        // second, and good credentials would be refused.
        $client = $this->client(authenticators: [new Login('jane', 'secret')]);

        $client->send($this->message('Before'));
        $client->close();

        $client->send($this->message('After'));
        $client->close();

        $this->assertCount(2, $this->messages());
    }

    public function testDeliversToEveryRecipientIncludingBlindOnes(): void
    {
        $client = $this->client();

        $result = $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Wide',
            text: 'Body',
            cc: [new Address('ada@example.test')],
            bcc: [new Address('eve@example.test')],
        ));

        $client->close();

        $this->assertSame(
            ['john@example.test', 'ada@example.test', 'eve@example.test'],
            $result->accepted,
        );

        $delivered = $this->delivered();
        $this->assertCount(1, $this->listOf($delivered, 'To'));
        $this->assertCount(1, $this->listOf($delivered, 'Cc'));

        // All three were delivered, but the blind one reached the server only
        // through RCPT TO. Mailpit records that by prepending its own Bcc,
        // Return-Path and Received fields, so the claim is about what we wrote:
        // everything from the Date header down.
        $source = $this->source();
        $ours = substr($source, (int) strpos($source, 'Date: '));

        $this->assertStringContainsString('Bcc: eve@example.test', $source, 'the server saw the blind recipient');
        $this->assertStringNotContainsString('eve@example.test', $ours, 'and we did not put it in a header');
        $this->assertStringNotContainsString('Bcc', $ours);
    }

    public function testReportsAPermanentlyRefusedRecipientWithoutLosingTheRest(): void
    {
        $client = $this->client();

        // The server is configured to allow @example.test and nothing else, so
        // the second recipient draws a real refusal mid-transaction.
        $result = $client->sendRaw(
            new Envelope('jane@example.test', ['john@example.test', 'blocked@example.invalid']),
            "Subject: Partial\r\n\r\nBody\r\n",
        );

        $client->close();

        $this->assertSame(['john@example.test'], $result->accepted);
        $this->assertArrayHasKey('blocked@example.invalid', $result->rejected);
        $this->assertSame(Outcome::Permanent, $result->rejected['blocked@example.invalid']->outcome);
        $this->assertSame('Partial', $this->field($this->delivered(), 'Subject'));
    }

    public function testReportsATransientlyRefusedRecipientAsWorthRetrying(): void
    {
        // The second server takes two recipients a message, so the third draws
        // a 4yz. This is the case the Result shape exists for: two people have
        // the message and the third is worth trying again later.
        $client = $this->client(Encryption::Implicit, port: self::IMPLICIT_PORT);

        $result = $client->sendRaw(
            new Envelope('jane@example.test', ['one@example.test', 'two@example.test', 'three@example.test']),
            "Subject: Crowded\r\n\r\nBody\r\n",
        );

        $client->close();

        $this->assertSame(['one@example.test', 'two@example.test'], $result->accepted);
        $this->assertArrayHasKey('three@example.test', $result->rejected);

        $refusal = $result->rejected['three@example.test'];
        $this->assertSame(Outcome::Transient, $refusal->outcome);
        $this->assertSame('4.5.3', $refusal->status);

        $this->assertSame('Crowded', $this->field($this->delivered(self::IMPLICIT_API), 'Subject'));
    }

    public function testSurfacesARefusalAsATransactionFailure(): void
    {
        $client = $this->client();

        try {
            $client->sendRaw(new Envelope('jane@example.test', ['blocked@example.invalid']), "Subject: X\r\n\r\nBody\r\n");
            $this->fail('Expected the send to fail');
        } catch (TransactionException $exception) {
            $this->assertTrue($exception->isPermanent());
            $this->assertFalse($exception->isTransient());
        }

        $client->close();
    }

    public function testStuffsADotThatWouldOtherwiseEndTheMessage(): void
    {
        $client = $this->client();

        $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Transparency',
            text: "before\r\n.\r\nafter",
        ));

        $client->close();

        // Without stuffing the server would have stopped reading at the dot and
        // the second half would be gone.
        $this->assertStringContainsString('after', $this->field($this->delivered(), 'Text'));
    }

    public function testTheEnvelopeSenderIsNotTheFromHeader(): void
    {
        $client = $this->client();

        $client->sendRaw(
            new Envelope('bounces@example.test', ['john@example.test']),
            (string) new Message(
                from: new Address('jane@example.test', 'Jane Doe'),
                to: [new Address('john@example.test')],
                subject: 'Split',
                text: 'Body',
            ),
        );

        $client->close();

        $delivered = $this->delivered();

        // Where a bounce goes and who the reader sees are different answers.
        $this->assertSame('bounces@example.test', $this->field($delivered, 'ReturnPath'));
        $this->assertSame('jane@example.test', $this->field($this->listOf($delivered, 'From'), 'Address'));
    }
}
