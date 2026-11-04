<?php

declare(strict_types=1);

namespace Tests\Unit\Certificates;

use Appwrite\Certificates\Certificates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class CertificatesTest extends TestCase
{
    #[DataProvider('autoIssueProvider')]
    public function testIsAutoIssueEnabled(string $edition, string $option, string $hostname, string $owner, bool $expected): void
    {
        $certificates = new Certificates($edition, $option);

        $this->assertSame($expected, $certificates->isAutoIssueEnabled(new Document([
            'domain' => $hostname,
            'owner' => $owner,
        ])));
    }

    public function testIsAutoIssueEnabledDefaults(): void
    {
        $certificates = new Certificates();

        $this->assertTrue($certificates->isAutoIssueEnabled(new Document([
            'domain' => 'example.com',
            'owner' => 'Appwrite',
        ])));
    }

    public static function autoIssueProvider(): \Iterator
    {
        yield 'enabled on self-hosted' => ['self-hosted', 'enabled', 'example.com', 'Appwrite', true];
        yield 'disabled by operator' => ['self-hosted', 'disabled', 'example.com', 'Appwrite', false];
        yield 'disabled outside self-hosted' => ['cloud', 'enabled', 'example.com', 'Appwrite', false];
        yield 'disabled for local domain' => ['self-hosted', 'enabled', 'localhost', 'Appwrite', false];
        yield 'disabled for non-Appwrite owner' => ['self-hosted', 'enabled', 'example.com', '', false];
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[DataProvider('renewalProvider')]
    public function testGetRenewalSkipReason(string $option, array $rule, ?string $expected): void
    {
        $certificates = new Certificates('self-hosted', $option);
        $certificate = new Document(['$id' => 'current', 'domain' => 'example.com']);

        $this->assertSame($expected, $certificates->getRenewalSkipReason($certificate, new Document($rule), 'default'));
    }

    public static function renewalProvider(): \Iterator
    {
        $rule = [
            '$id' => \md5('example.com'),
            'domain' => 'example.com',
            'region' => 'default',
            'status' => RULE_STATUS_VERIFIED,
            'certificateId' => 'current',
            'owner' => '',
        ];

        yield 'verified rule pointing to the row' => ['enabled', $rule, null];
        yield 'verifying rule' => ['enabled', ['status' => RULE_STATUS_CERTIFICATE_GENERATING] + $rule, null];
        yield 'rule without a row yet' => ['enabled', ['certificateId' => ''] + $rule, null];
        yield 'rule with a null row' => ['enabled', ['certificateId' => null] + $rule, null];
        yield 'Appwrite subdomain with auto-issue on' => ['enabled', ['owner' => 'Appwrite'] + $rule, null];
        yield 'custom domain with auto-issue off' => ['disabled', $rule, null];
        yield 'no rule' => ['enabled', [], 'orphaned'];
        yield 'rule of another region' => ['enabled', ['region' => 'elsewhere'] + $rule, 'region'];
        yield 'rule pointing to another row' => ['enabled', ['certificateId' => 'previous'] + $rule, 'superseded'];
        yield 'unverified rule' => ['enabled', ['status' => RULE_STATUS_CERTIFICATE_GENERATION_FAILED] + $rule, 'failed'];
        yield 'created rule' => ['enabled', ['status' => RULE_STATUS_CREATED] + $rule, 'failed'];
        yield 'Appwrite subdomain with auto-issue off' => ['disabled', ['owner' => 'Appwrite'] + $rule, 'disabled'];
        yield 'another region outranks a failed rule' => ['enabled', ['region' => 'elsewhere', 'status' => RULE_STATUS_CERTIFICATE_GENERATION_FAILED] + $rule, 'region'];
        yield 'a superseded row is not counted as failed' => ['enabled', ['certificateId' => 'previous', 'status' => RULE_STATUS_CERTIFICATE_GENERATION_FAILED] + $rule, 'superseded'];
    }
}
