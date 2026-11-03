<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\Rule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class RuleTest extends TestCase
{
    #[DataProvider('statuses')]
    public function testResponseReportsTheApiStatus(string $stored, string $reported): void
    {
        $rule = (new Rule())->filter(new Document(['status' => $stored]));

        $this->assertSame($reported, $rule->getAttribute('status'));
    }

    public function testEventPayloadReportsTheSameStatusAsTheResponse(): void
    {
        // Workers publish rule updates through the payload, not a response
        $payload = (new Rule())->payload(new Document([
            '$id' => 'rule-1',
            'domain' => 'www.example.com',
            'status' => RULE_STATUS_CREATED,
        ]));

        $this->assertSame('unverified', $payload['status']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function statuses(): iterable
    {
        yield 'dns not verified yet' => [RULE_STATUS_CREATED, 'unverified'];
        yield 'certificate generation failed' => [RULE_STATUS_CERTIFICATE_GENERATION_FAILED, 'unverified'];
        yield 'generating' => [RULE_STATUS_CERTIFICATE_GENERATING, 'verifying'];
        yield 'verified' => [RULE_STATUS_VERIFIED, 'verified'];
    }
}
