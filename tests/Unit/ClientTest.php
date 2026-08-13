<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Address;
use Utopia\SMTP\Auth\Login;
use Utopia\SMTP\Auth\Plain;
use Utopia\SMTP\Client;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Envelope;
use Utopia\SMTP\Exception\AuthenticationException;
use Utopia\SMTP\Exception\CapabilityException;
use Utopia\SMTP\Exception\ConnectionException;
use Utopia\SMTP\Exception\ProtocolException;
use Utopia\SMTP\Exception\TransactionException;
use Utopia\SMTP\Message;
use Utopia\SMTP\Outcome;
use Utopia\SMTP\Tests\Unit\Support\FakeTransport;

final class ClientTest extends TestCase
{
    /**
     * The greeting and a plain EHLO, followed by whatever the test adds.
     *
     * @param  list<string>  $replies
     */
    private function transport(array $replies = [], string ...$capabilities): FakeTransport
    {
        // The greeting opens an EHLO reply; the keywords follow it.
        $ehlo = ['mail.example.test', ...$capabilities];
        $last = array_pop($ehlo);

        $lines = ['220 mail.example.test ESMTP'];

        foreach ($ehlo as $line) {
            $lines[] = "250-{$line}";
        }

        $lines[] = "250 {$last}";

        return new FakeTransport([...$lines, ...$replies]);
    }

    /**
     * A full accepted transaction.
     *
     * @return list<string>
     */
    private function transaction(string $final = '250 2.0.0 Ok: queued as ABC123'): array
    {
        return ['250 2.1.0 Sender ok', '250 2.1.5 Recipient ok', '354 End data with <CR><LF>.<CR><LF>', $final];
    }

    private function envelope(): Envelope
    {
        return new Envelope('jane@example.test', ['john@example.test']);
    }

    public function testWalksTheTransaction(): void
    {
        $transport = $this->transport($this->transaction());
        $client = new Client($transport, 'relay.example.test', encryption: Encryption::None);

        $result = $client->sendRaw($this->envelope(), "Subject: Hi\r\n\r\nBody");

        $this->assertSame([
            'EHLO relay.example.test',
            'MAIL FROM:<jane@example.test>',
            'RCPT TO:<john@example.test>',
            'DATA',
            'Subject: Hi',
            'Body',
            '.',
        ], $transport->commands());

        $this->assertSame(['john@example.test'], $result->accepted);
        $this->assertSame([], $result->rejected);
        $this->assertTrue($result->isComplete());
    }

    public function testReadsTheQueueIdentifier(): void
    {
        $transport = $this->transport($this->transaction());
        $client = new Client($transport, encryption: Encryption::None);

        $this->assertSame('ABC123', $client->sendRaw($this->envelope(), 'Body')->messageId);
    }

    public function testSurvivesAServerThatOffersNoIdentifier(): void
    {
        $transport = $this->transport($this->transaction('250 Ok'));
        $client = new Client($transport, encryption: Encryption::None);

        $this->assertSame('', $client->sendRaw($this->envelope(), 'Body')->messageId);
    }

    public function testFallsBackToHeloWhenEhloIsRefused(): void
    {
        $transport = new FakeTransport([
            '220 mail.example.test',
            '500 Command not recognised',
            '250 mail.example.test',
            ...$this->transaction(),
        ]);
        $client = new Client($transport, 'relay.example.test', encryption: Encryption::None);

        $client->sendRaw($this->envelope(), 'Body');

        $this->assertSame(['EHLO relay.example.test', 'HELO relay.example.test'], \array_slice($transport->commands(), 0, 2));
    }

    public function testKeepsSendingWhenOnlySomeRecipientsAreRefused(): void
    {
        $transport = $this->transport([
            '250 Sender ok',
            '250 Recipient ok',
            '550 5.1.1 No such user',
            '354 Go ahead',
            '250 Ok: queued as ABC123',
        ]);
        $client = new Client($transport, encryption: Encryption::None);

        $result = $client->sendRaw(
            new Envelope('jane@example.test', ['john@example.test', 'ghost@example.test']),
            'Body',
        );

        $this->assertSame(['john@example.test'], $result->accepted);
        $this->assertSame(['ghost@example.test'], array_keys($result->rejected));
        $this->assertSame(Outcome::Permanent, $result->rejected['ghost@example.test']->outcome);
        $this->assertFalse($result->isComplete());
    }

    public function testAcceptsTheForwardingRepliesOfRfc5321(): void
    {
        $transport = $this->transport(['250 Sender ok', '251 User not local; will forward', '354 Go ahead', '250 Ok']);
        $client = new Client($transport, encryption: Encryption::None);

        $this->assertSame(['john@example.test'], $client->sendRaw($this->envelope(), 'Body')->accepted);
    }

    public function testGivesUpWhenEveryRecipientIsRefused(): void
    {
        $transport = $this->transport(['250 Sender ok', '450 4.2.1 Mailbox busy', '250 Reset ok']);
        $client = new Client($transport, encryption: Encryption::None);

        try {
            $client->sendRaw($this->envelope(), 'Body');
            $this->fail('Expected the send to fail');
        } catch (TransactionException $exception) {
            $this->assertTrue($exception->isTransient());
            $this->assertSame('4.2.1', $exception->reply->status);
        }

        $this->assertContains('RSET', $transport->commands());
    }

    public function testUpgradesWhenTheServerOffersStartTls(): void
    {
        $transport = $this->transport(['220 Ready to start TLS', '250 mail.example.test', ...$this->transaction()], 'STARTTLS');
        $client = new Client($transport, 'relay.example.test');

        $client->sendRaw($this->envelope(), 'Body');

        $this->assertSame(1, $transport->handshakes);
        $this->assertTrue($transport->isTls());

        // RFC 3207 section 4.2: EHLO is reissued and the old capabilities dropped.
        $commands = $transport->commands();
        $this->assertSame('EHLO relay.example.test', $commands[0]);
        $this->assertSame('STARTTLS', $commands[1]);
        $this->assertSame('EHLO relay.example.test', $commands[2]);
    }

    public function testStaysPlaintextWhenTheServerDoesNotOfferStartTls(): void
    {
        $transport = $this->transport($this->transaction());
        $client = new Client($transport);

        $client->sendRaw($this->envelope(), 'Body');

        $this->assertSame(0, $transport->handshakes);
    }

    public function testRefusesToContinueWhenStartTlsIsRequiredAndMissing(): void
    {
        $client = new Client($this->transport(), encryption: Encryption::StartTls);

        $this->expectException(CapabilityException::class);

        $client->sendRaw($this->envelope(), 'Body');
    }

    public function testConnectsWrappedWhenTlsIsImplicit(): void
    {
        $transport = $this->transport($this->transaction());
        $client = new Client($transport, encryption: Encryption::Implicit);

        $client->sendRaw($this->envelope(), 'Body');

        $this->assertTrue($transport->isTls());
        $this->assertSame(0, $transport->handshakes, 'implicit TLS needs no upgrade');
    }

    public function testDropsAnythingBufferedBeforeTheHandshake(): void
    {
        // A greedy transport hands the injected line over along with the 220, so
        // it is sitting unread in the client's buffer when the handshake finishes.
        // Reusing it would mean trusting bytes that arrived in the clear.
        $transport = new FakeTransport(
            [
                '220 mail.example.test ESMTP',
                '250-mail.example.test',
                '250 STARTTLS',
                '220 Ready to start TLS',
                '250 2.7.0 Injected before the handshake',
            ],
            greedy: true,
            afterHandshake: [
                '250-mail.example.test',
                '250 SIZE 100',
                ...$this->transaction(),
            ],
        );
        $client = new Client($transport);

        $client->sendRaw($this->envelope(), 'Body');

        $this->assertSame(1, $transport->handshakes);

        // The capabilities came from the reply read after the handshake, not
        // from the line injected before it.
        $this->assertSame(100, $client->capabilities()->maxSize());
    }

    public function testAuthenticatesWithAnInitialResponse(): void
    {
        $transport = $this->transport(['235 2.7.0 Authenticated', ...$this->transaction()], 'AUTH PLAIN LOGIN');
        $client = new Client($transport, authenticators: [new Plain('jane', 'secret')], encryption: Encryption::None);

        $client->sendRaw($this->envelope(), 'Body');

        $this->assertContains('AUTH PLAIN ' . base64_encode("\0jane\0secret"), $transport->commands());
    }

    public function testAuthenticatesThroughChallenges(): void
    {
        $transport = $this->transport([
            '334 ' . base64_encode('Username:'),
            '334 ' . base64_encode('Password:'),
            '235 2.7.0 Authenticated',
            ...$this->transaction(),
        ], 'AUTH LOGIN');
        $client = new Client($transport, authenticators: [new Login('jane', 'secret')], encryption: Encryption::None);

        $client->sendRaw($this->envelope(), 'Body');

        $commands = $transport->commands();
        $this->assertContains('AUTH LOGIN', $commands);
        $this->assertContains(base64_encode('jane'), $commands);
        $this->assertContains(base64_encode('secret'), $commands);
    }

    public function testPicksTheFirstMechanismTheServerShares(): void
    {
        $transport = $this->transport(['235 Authenticated', ...$this->transaction()], 'AUTH LOGIN');
        $client = new Client(
            $transport,
            authenticators: [new Plain('jane', 'secret'), new Login('jane', 'secret')],
            encryption: Encryption::None,
        );

        $client->sendRaw($this->envelope(), 'Body');

        $this->assertContains('AUTH LOGIN', $transport->commands());
    }

    public function testFailsWhenNoMechanismIsShared(): void
    {
        $client = new Client(
            $this->transport([], 'AUTH GSSAPI'),
            authenticators: [new Plain('jane', 'secret')],
            encryption: Encryption::None,
        );

        $this->expectException(AuthenticationException::class);

        $client->sendRaw($this->envelope(), 'Body');
    }

    public function testFailsWhenCredentialsAreRefused(): void
    {
        $client = new Client(
            $this->transport(['535 5.7.8 Authentication credentials invalid'], 'AUTH PLAIN'),
            authenticators: [new Plain('jane', 'wrong')],
            encryption: Encryption::None,
        );

        $this->expectException(AuthenticationException::class);

        $client->sendRaw($this->envelope(), 'Body');
    }

    public function testDeclaresTheSizeWhenTheServerAsksForIt(): void
    {
        $transport = $this->transport($this->transaction(), 'SIZE 1000');
        $client = new Client($transport, encryption: Encryption::None);

        $client->sendRaw($this->envelope(), 'Body');

        $this->assertContains('MAIL FROM:<jane@example.test> SIZE=4', $transport->commands());
    }

    public function testRefusesAMessageOverTheServerLimit(): void
    {
        $client = new Client($this->transport([], 'SIZE 10'), encryption: Encryption::None);

        $this->expectException(CapabilityException::class);

        $client->sendRaw($this->envelope(), str_repeat('x', 11));
    }

    public function testDeclaresSmtpUtf8ForAnInternationalPath(): void
    {
        $transport = $this->transport($this->transaction(), 'SMTPUTF8');
        $client = new Client($transport, encryption: Encryption::None);

        $client->sendRaw(new Envelope('jäne@example.test', ['john@example.test']), 'Body');

        $this->assertContains('MAIL FROM:<jäne@example.test> SMTPUTF8', $transport->commands());
    }

    public function testDeclaresSmtpUtf8ForAMessageWhoseHeadersNeedIt(): void
    {
        // Every path here is ASCII. Only the Reply-To needs UTF-8, and it is a
        // header, so it appears in no RCPT TO — but the extension is still what
        // lets the server accept it.
        $transport = $this->transport($this->transaction(), 'SMTPUTF8');
        $client = new Client($transport, encryption: Encryption::None);

        $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Hello',
            text: 'Body',
            replyTo: [new Address('jäne@example.test')],
        ));

        $this->assertContains('MAIL FROM:<jane@example.test> SMTPUTF8', $transport->commands());
    }

    public function testRefusesAMessageWhoseHeadersTheServerCannotCarry(): void
    {
        $client = new Client($this->transport(), encryption: Encryption::None);

        $this->expectException(CapabilityException::class);

        $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Hello',
            text: 'Body',
            replyTo: [new Address('jäne@example.test')],
        ));
    }

    public function testRefusesAnInternationalPathTheServerCannotCarry(): void
    {
        $client = new Client($this->transport(), encryption: Encryption::None);

        $this->expectException(CapabilityException::class);

        $client->sendRaw(new Envelope('jäne@example.test', ['john@example.test']), 'Body');
    }

    public function testThrowsAwayAConnectionInterruptedDuringData(): void
    {
        // The terminating dot never reaches the server, so it is still reading
        // message data. Keeping the connection would send the next MAIL FROM as
        // content of this message.
        $transport = new FakeTransport(
            [
                '220 mail.example.test',
                '250 mail.example.test',
                '250 Sender ok',
                '250 Recipient ok',
                '354 Go ahead',
            ],
            failWriting: 'BOOM',
        );
        $client = new Client($transport, encryption: Encryption::None);

        try {
            $client->sendRaw($this->envelope(), 'a message that goes BOOM part way through');
            $this->fail('Expected the send to fail');
        } catch (ConnectionException) {
            // expected
        }

        $this->assertTrue($transport->closed, 'the transport should have been dropped');
        $this->assertNotContains('QUIT', $transport->commands(), 'there is no point saying goodbye mid-DATA');
    }

    public function testThrowsAwayAConnectionThatDiesOnTheFinalReply(): void
    {
        // The dot went out, but the server never answered. We cannot know
        // whether it took the message, and the stream is finished either way.
        $transport = $this->transport(['250 Sender ok', '250 Recipient ok', '354 Go ahead']);
        $client = new Client($transport, encryption: Encryption::None);

        try {
            $client->sendRaw($this->envelope(), 'Body');
            $this->fail('Expected the send to fail');
        } catch (ConnectionException) {
            // expected
        }

        $this->assertTrue($transport->closed, 'the transport should have been dropped');
    }

    public function testThrowsAwayAConnectionLeftOutOfStep(): void
    {
        // A final reply we cannot parse means the stream is no longer aligned
        // with the protocol. Reading the next reply would read the rest of this
        // one, and every reply after would answer the wrong command.
        $transport = $this->transport([...$this->transaction(), '250 Ok']);
        $client = new Client($transport, encryption: Encryption::None);

        $client->sendRaw($this->envelope(), 'Body');

        $transport->reply('garbage with no code');

        try {
            $client->sendRaw($this->envelope(), 'Body');
            $this->fail('Expected the send to fail');
        } catch (ProtocolException) {
            // expected
        }

        $this->assertTrue($transport->closed);
    }

    public function testReconnectsAfterTheConnectionWasDropped(): void
    {
        $transport = $this->transport(['250 Sender ok', '250 Recipient ok', '354 Go ahead']);
        $client = new Client($transport, encryption: Encryption::None);

        try {
            $client->sendRaw($this->envelope(), 'Body');
        } catch (ConnectionException) {
            // expected
        }

        // A fresh session, so the greeting and EHLO are read again rather than
        // MAIL FROM being sent onto a dead socket.
        $transport->reply('220 mail.example.test ESMTP', '250 mail.example.test', ...$this->transaction());

        $client->sendRaw($this->envelope(), 'Body');

        $commands = $transport->commands();
        $this->assertCount(2, array_filter($commands, static fn(string $line): bool => str_starts_with($line, 'EHLO')));
    }

    public function testKeepsTheConnectionWhenTheServerRefusesTheData(): void
    {
        // A refusal after the dot is clean: the server is back in command state.
        $transport = $this->transport([...$this->transaction('552 5.3.4 Message too big'), '221 Bye']);
        $client = new Client($transport, encryption: Encryption::None);

        try {
            $client->sendRaw($this->envelope(), 'Body');
            $this->fail('Expected the send to fail');
        } catch (TransactionException $exception) {
            $this->assertTrue($exception->isPermanent());
        }

        $this->assertFalse($transport->closed);

        $client->close();
        $this->assertContains('QUIT', $transport->commands());
    }

    public function testReadsTheQueueIdentifierInItsOtherShapes(): void
    {
        foreach ([
            '250 2.0.0 Ok: queued as ABC123' => 'ABC123',
            '250 Message accepted id=1r8xyz-0002Ab-Hu' => '1r8xyz-0002Ab-Hu',
            '250 Ok 0123456789abcdef' => '0123456789abcdef',
            '250 Message accepted' => '',
        ] as $reply => $expected) {
            $client = new Client($this->transport($this->transaction($reply)), encryption: Encryption::None);

            $this->assertSame($expected, $client->sendRaw($this->envelope(), 'Body')->messageId, $reply);
        }
    }

    public function testNoopKeepsTheSessionAlive(): void
    {
        $transport = $this->transport(['250 2.0.0 Ok']);
        $client = new Client($transport, encryption: Encryption::None);

        $client->capabilities();
        $client->noop();

        $this->assertContains('NOOP', $transport->commands());
    }

    public function testGivesUpOnAMechanismThatKeepsChallenging(): void
    {
        // A server answering every response with another challenge is not going
        // to authenticate us, and the exchange must not run forever.
        $challenges = array_fill(0, 40, '334 ' . base64_encode('Again:'));

        $client = new Client(
            $this->transport($challenges, 'AUTH LOGIN'),
            authenticators: [new Login('jane', 'secret')],
            encryption: Encryption::None,
        );

        $this->expectException(AuthenticationException::class);

        $client->sendRaw($this->envelope(), 'Body');
    }

    public function testQuitsOnClose(): void
    {
        $transport = $this->transport([...$this->transaction(), '221 2.0.0 Bye']);
        $client = new Client($transport, encryption: Encryption::None);

        $client->sendRaw($this->envelope(), 'Body');
        $client->close();

        $this->assertContains('QUIT', $transport->commands());
        $this->assertTrue($transport->closed);
    }

    public function testClosesWithoutGreetingWhenNothingWasSent(): void
    {
        $transport = $this->transport();
        $client = new Client($transport, encryption: Encryption::None);

        $client->close();

        $this->assertSame([], $transport->commands());
        $this->assertTrue($transport->closed);
    }

    public function testRejectsACommandThatSpansLines(): void
    {
        $client = new Client($this->transport(), encryption: Encryption::None);

        $this->expectException(InvalidArgumentException::class);

        $client->command("NOOP\r\nRCPT TO:<eve@example.test>", [250]);
    }

    public function testRejectsAReplyThatIsNotAReply(): void
    {
        $client = new Client(new FakeTransport(['hello there']), encryption: Encryption::None);

        $this->expectException(ProtocolException::class);

        $client->sendRaw($this->envelope(), 'Body');
    }

    public function testReadsAContinuedReply(): void
    {
        $transport = $this->transport($this->transaction(), 'PIPELINING', 'SIZE 100', '8BITMIME');
        $client = new Client($transport, encryption: Encryption::None);

        $capabilities = $client->capabilities();

        $this->assertTrue($capabilities->has('PIPELINING'));
        $this->assertTrue($capabilities->has('8BITMIME'));
        $this->assertSame(100, $capabilities->maxSize());
    }
}
