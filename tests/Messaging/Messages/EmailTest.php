<?php

declare(strict_types=1);

namespace Utopia\Tests\Messages;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Messaging\Exception\InvalidArgumentException;
use Utopia\Messaging\Messages\Email;

final class EmailTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string}>
     */
    public static function undeliverableRecipients(): \Iterator
    {
        yield 'no at' => ['john', InvalidArgumentException::RECIPIENT_MALFORMED];
        yield 'angle brackets' => ['John <john@appwrite.io>', InvalidArgumentException::RECIPIENT_MALFORMED];
        yield 'one letter tld' => ['john@c.c', InvalidArgumentException::RECIPIENT_DOMAIN_INVALID];
        yield 'digits in tld' => ['john@gyung.me976153', InvalidArgumentException::RECIPIENT_DOMAIN_INVALID];
    }

    #[DataProvider('undeliverableRecipients')]
    public function testUndeliverableRecipientIsRefused(string $email, string $type): void
    {
        try {
            $this->message(to: [['email' => $email, 'name' => 'John']]);
            $this->fail('Expected ' . $type);
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($type, $exception->getType());
            $this->assertSame($email, $exception->getValue());
        }
    }

    public function testDocumentationDomainIsWellFormed(): void
    {
        $message = $this->message(to: ['john@example.com', 'john@xn--bcher-kva.example']);

        $this->assertCount(2, $message->getTo());
    }

    public function testRecipientNameWithLineBreakIsRefused(): void
    {
        try {
            $this->message(to: [['email' => 'john@appwrite.io', 'name' => "John\nDoe"]]);
            $this->fail('Expected name failure');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(InvalidArgumentException::NAME_MALFORMED, $exception->getType());
        }
    }

    public function testCarbonCopyRecipientsAreValidatedToo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->message(to: ['john@appwrite.io'], bcc: ['not an address']);
    }

    /**
     * @return \Iterator<string, array{string}>
     */
    public static function malformedSenders(): \Iterator
    {
        yield 'no domain' => ['noreply'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformedSenders')]
    public function testMalformedSenderIsRefused(string $fromEmail): void
    {
        try {
            $this->message(to: ['john@appwrite.io'], fromEmail: $fromEmail);
            $this->fail('Expected sender failure');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(InvalidArgumentException::SENDER_MALFORMED, $exception->getType());
        }
    }

    public function testEmptyRecipientKeepsItsType(): void
    {
        try {
            $this->message(to: ['']);
            $this->fail('Expected empty recipient failure');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(InvalidArgumentException::RECIPIENT_EMPTY, $exception->getType());
        }
    }

    public function testTypedExceptionIsStillAnInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->message(to: ['nope']);
    }

    /**
     * @param  array<string|array<string, string>>  $to
     * @param  array<string|array<string, string>>|null  $bcc
     */
    private function message(array $to, ?array $bcc = null, string $fromEmail = 'noreply@appwrite.io'): Email
    {
        return new Email(
            to: $to,
            subject: 'Subject',
            content: 'Body',
            fromName: 'Sender',
            fromEmail: $fromEmail,
            bcc: $bcc,
        );
    }
}
