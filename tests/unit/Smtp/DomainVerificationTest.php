<?php

declare(strict_types=1);

namespace Tests\Unit\Smtp;

use Appwrite\Extend\Exception;
use Appwrite\Smtp\DomainVerification;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\DNS\Message\Record;

final class DomainVerificationTest extends TestCase
{
    private string|false $smtpTarget;

    protected function setUp(): void
    {
        $this->smtpTarget = getenv('_APP_DOMAIN_TARGET_SMTP');
        putenv('_APP_DOMAIN_TARGET_SMTP=mx.appwrite.test');
        FakeDNS::$valid = true;
        FakeDNS::$queries = [];
    }

    protected function tearDown(): void
    {
        putenv($this->smtpTarget === false
            ? '_APP_DOMAIN_TARGET_SMTP'
            : '_APP_DOMAIN_TARGET_SMTP='.$this->smtpTarget);
    }

    public function test_verifies_mx_and_ownership_txt(): void
    {
        (new DomainVerification(FakeDNS::class))->verify(new Document([
            'domain' => 'example.com',
            'verificationToken' => 'token',
        ]));

        $this->assertSame([
            ['mx.appwrite.test', Record::TYPE_MX, 'example.com'],
            ['appwrite-domain-verification=token', Record::TYPE_TXT, '_appwrite.example.com'],
        ], FakeDNS::$queries);
    }

    public function test_rejects_failed_dns_validation(): void
    {
        FakeDNS::$valid = false;

        $this->expectException(Exception::class);
        $this->expectExceptionCode(400);

        (new DomainVerification(FakeDNS::class))->verify(new Document([
            'domain' => 'example.com',
            'verificationToken' => 'token',
        ]));
    }
}

final class FakeDNS
{
    public static bool $valid = true;

    /** @var list<array{string, int, string}> */
    public static array $queries = [];

    public function __construct(
        private readonly string $target,
        private readonly int $type,
    ) {
    }

    public function isValid(mixed $value): bool
    {
        self::$queries[] = [$this->target, $this->type, (string) $value];

        return self::$valid;
    }

    public function getDescription(): string
    {
        return 'DNS validation failed.';
    }
}
