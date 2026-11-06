<?php

declare(strict_types=1);

namespace Utopia\DNS\Tests\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\DNS;

/**
 * Answers from a fixed record list instead of a resolver.
 */
final class AnsweringDNS extends DNS
{
    /**
     * @param array<string> $answers
     */
    public function __construct(string $target, int $type, private readonly array $answers)
    {
        parent::__construct($target, $type);
    }

    protected function query(string $value): array
    {
        return \array_map(fn (string $rdata): Record => new Record(name: $value, type: $this->type, rdata: $rdata), $this->answers);
    }
}

final class DNSTest extends TestCase
{
    public function testQueryFailureNamesTypeAndResolver(): void
    {
        // Nothing listens on loopback port 53, so the query fails without a network
        $validator = new DNS('appwrite.io', Record::TYPE_CNAME, '127.0.0.1');

        $this->assertFalse($validator->isValid('example.com'));
        $this->assertSame('DNS CNAME query to 127.0.0.1 timed out or failed.', $validator->getDescription());
    }

    /**
     * @return array<string, array{string, int, array<string>, bool}>
     */
    public static function records(): array
    {
        return [
            'caa issue for target' => ['digicert.com', Record::TYPE_CAA, ['0 issue "digicert.com"'], true],
            'caa issue for another ca, issuewild for target' => ['digicert.com', Record::TYPE_CAA, ['0 issue "letsencrypt.org"', '0 issuewild "digicert.com"'], false],
            'caa issuewild for target, issue for target' => ['digicert.com', Record::TYPE_CAA, ['0 issuewild "letsencrypt.org"', '0 issue "digicert.com"'], true],
            'caa issuewild only for target' => ['digicert.com', Record::TYPE_CAA, ['128 issuewild "digicert.com;validationmethods=dns-01"'], true],
            'caa issuewild only for another ca' => ['digicert.com', Record::TYPE_CAA, ['0 issuewild "letsencrypt.org"'], true],
            'caa issue for another ca' => ['digicert.com', Record::TYPE_CAA, ['0 issue "letsencrypt.org"'], false],
            'caa capitalised issuer' => ['digicert.com', Record::TYPE_CAA, ['0 issue "DigiCert.com"'], true],
            'caa capitalised tag' => ['digicert.com', Record::TYPE_CAA, ['0 ISSUE "letsencrypt.org"', '0 issuewild "digicert.com"'], false],
            'cname exact' => ['sites.appwrite.network', Record::TYPE_CNAME, ['sites.appwrite.network'], true],
            'cname capitalised' => ['sites.appwrite.network', Record::TYPE_CNAME, ['Sites.Appwrite.Network'], true],
            'cname fully qualified' => ['sites.appwrite.network', Record::TYPE_CNAME, ['sites.appwrite.network.'], true],
            'cname elsewhere' => ['sites.appwrite.network', Record::TYPE_CNAME, ['sites.example.com'], false],
            'ns capitalised' => ['ns1.appwrite.zone', Record::TYPE_NS, ['NS1.appwrite.zone.'], true],
            'txt stays exact' => ['Secret', Record::TYPE_TXT, ['secret'], false],
        ];
    }

    /**
     * @param array<string> $answers
     */
    #[DataProvider('records')]
    public function testRecordMatching(string $target, int $type, array $answers, bool $expected): void
    {
        $validator = new AnsweringDNS($target, $type, $answers);

        $this->assertSame($expected, $validator->isValid('shop.example.com'));
    }
}
