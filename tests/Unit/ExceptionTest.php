<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Address;
use Utopia\SMTP\Attachment;
use Utopia\SMTP\Client;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Envelope;
use Utopia\SMTP\Exception\AuthenticationException;
use Utopia\SMTP\Exception\CapabilityException;
use Utopia\SMTP\Exception\ConnectionException;
use Utopia\SMTP\Exception\MessageException;
use Utopia\SMTP\Exception\ProtocolException;
use Utopia\SMTP\Exception\SmtpException;
use Utopia\SMTP\Exception\TimeoutException;
use Utopia\SMTP\Exception\TransactionException;
use Utopia\SMTP\Message;
use Utopia\SMTP\Outcome;
use Utopia\SMTP\Reply;
use Utopia\SMTP\Tests\Unit\Support\FakeTransport;
use Utopia\SMTP\Transport\Native;

/**
 * The shape of the hierarchy, which is a promise to callers rather than an
 * implementation detail: what one catch statement covers, and what it does not.
 */
final class ExceptionTest extends TestCase
{
    /**
     * @return \Iterator<int, array{class-string<SmtpException>}>
     */
    public static function runtimeFailures(): \Iterator
    {
        yield [ConnectionException::class];
        yield [TimeoutException::class];
        yield [ProtocolException::class];
        yield [AuthenticationException::class];
        yield [CapabilityException::class];
        yield [MessageException::class];
    }

    /**
     * @param  class-string<SmtpException>  $failure
     */
    #[DataProvider('runtimeFailures')]
    public function testEveryRuntimeFailureIsOneCategory(string $failure): void
    {
        $this->assertInstanceOf(SmtpException::class, new $failure('because'));
    }

    public function testATransactionFailureIsOneToo(): void
    {
        $this->assertInstanceOf(SmtpException::class, new TransactionException(new Reply(550, ['No'])));
    }

    public function testATimeoutIsAConnectionFailure(): void
    {
        // Narrower than a dropped socket, and a caller who does not care about
        // the difference should not have to write two catch statements.
        $this->assertInstanceOf(ConnectionException::class, new TimeoutException('too slow'));
    }

    public function testACallerMistakeIsNotASmtpFailure(): void
    {
        // Catching SmtpException must not swallow a bug in the calling code,
        // so the package base is caught first and failing there is the point.
        try {
            new Address('not an address');
            $this->fail('Expected the address to be refused');
        } catch (SmtpException) {
            $this->fail('A caller mistake was raised as a protocol failure');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Not an address', $exception->getMessage());
        }
    }

    public function testUsingATransportBeforeConnectingIsALogicError(): void
    {
        $this->expectException(\LogicException::class);

        (new Native('127.0.0.1', 1))->read(8192, 1.0);
    }

    public function testATransactionFailureCarriesTheReply(): void
    {
        $reply = new Reply(451, ['4.3.0 Try later']);
        $exception = new TransactionException($reply);

        $this->assertSame($reply, $exception->reply);
        $this->assertSame(451, $exception->getCode());
        $this->assertSame(Outcome::Transient, $exception->reply->outcome);
        $this->assertTrue($exception->isTransient());
        $this->assertFalse($exception->isPermanent());
    }

    public function testAnUnreadableAttachmentIsAMessageFailure(): void
    {
        // The file was readable when the attachment was made and is not now, so
        // this is not a caller mistake: it happened while the message was being
        // written to the wire.
        $path = sys_get_temp_dir() . '/utopia-smtp-' . bin2hex(random_bytes(8));
        file_put_contents($path, 'x');

        $attachment = Attachment::fromPath($path);
        unlink($path);

        $message = new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Gone',
            text: 'Body',
            attachments: [$attachment],
        );

        $this->expectException(MessageException::class);

        // Consumed, so Rector cannot drop the call as having no effect and
        // leave the expectation unreachable.
        $this->assertSame('', (string) $message);
    }

    public function testACatchOfTheBaseCoversAProtocolFailure(): void
    {
        $client = new Client(new FakeTransport(['nonsense']), encryption: Encryption::None);

        try {
            $client->sendRaw(new Envelope('jane@example.test', ['john@example.test']), 'Body');
            $this->fail('Expected the send to fail');
        } catch (SmtpException $exception) {
            $this->assertInstanceOf(ProtocolException::class, $exception);
        }
    }
}
