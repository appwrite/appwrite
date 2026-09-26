<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Message\Record;
use Utopia\DNS\Validator\Name;

final class NameTest extends TestCase
{
    public function testValid(): void
    {
        $validator = new Name(Record::TYPE_CNAME);

        $validValues = [
            '@',
            'example',
            'example.com',
            'EXAMPLE.COM',
            'a-b.com',
            'a123.example-domain.org',
            'xn--d1acufc.xn--p1ai',
            '123.com',
            'example.com.',
            str_repeat('a', 63) . '.com',
            // RFC 4592: wildcard as the entire leftmost label
            '*',
            '*.',
            '*.example.com',
            '*.example.com.',
            // RFC 8552: underscored owner names are legal for non-address records
            '_dmarc',
            '_acme-challenge',
            'selector1._domainkey',
            'mail._domainkey.example.com',
            'exa_mple.com',
        ];

        foreach ($validValues as $value) {
            $this->assertTrue($validator->isValid($value), "Expected valid: {$value}");
        }

        $validator = new Name(Record::TYPE_SRV);
        $this->assertTrue($validator->isValid('example._tcp.com'), 'Expected valid: example._tcp.com');

        // No record type applies the general domain name rules
        $validator = new Name();
        $this->assertTrue($validator->isValid('selector1._domainkey'), 'Expected valid: selector1._domainkey');
        $this->assertTrue($validator->isValid('*.example.com'), 'Expected valid: *.example.com');

        // Address records still allow wildcards, just not underscores
        $validator = new Name(Record::TYPE_A);
        $this->assertTrue($validator->isValid('*'), 'Expected valid: *');
        $this->assertTrue($validator->isValid('*.example.com'), 'Expected valid: *.example.com');
        $this->assertFalse($validator->isValid('_dmarc'), 'Expected invalid: _dmarc');
    }

    public function testInvalid(): void
    {
        // Address records: owner name must be a valid host name (no underscores)
        $validator = new Name(Record::TYPE_A);

        $invalidValues = [
            ['value' => 123, 'description' => Name::FAILURE_REASON_GENERAL],
            ['value' => '', 'description' => Name::FAILURE_REASON_INVALID_NAME_LENGTH],
            ['value' => str_repeat('a', 256) . '.com', 'description' => Name::FAILURE_REASON_INVALID_NAME_LENGTH],
            ['value' => str_repeat('a', 64) . '.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_LENGTH],
            ['value' => '@.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE],
            ['value' => '-example.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE],
            ['value' => 'example-.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE],
            ['value' => 'exa_mple.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE],
            ['value' => 'example..com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE],
            ['value' => '.example.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE],
            ['value' => 'example.com..', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE],
            ['value' => 'exa mple.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITHOUT_UNDERSCORE],
        ];

        foreach ($invalidValues as $value) {
            $this->assertFalse($validator->isValid($value['value']), "Expected invalid: {$value['value']}");
            $this->assertSame($value['description'], $validator->getDescription());
        }

        // Non-address records: underscores allowed, everything else still invalid
        $validator = new Name(Record::TYPE_TXT);

        $invalidValues = [
            ['value' => 123, 'description' => Name::FAILURE_REASON_GENERAL],
            ['value' => '', 'description' => Name::FAILURE_REASON_INVALID_NAME_LENGTH],
            ['value' => str_repeat('a', 256) . '.com', 'description' => Name::FAILURE_REASON_INVALID_NAME_LENGTH],
            ['value' => str_repeat('a', 64) . '.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_LENGTH],
            ['value' => '@.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE],
            ['value' => '-example.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE],
            ['value' => 'example-.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE],
            ['value' => 'example..com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE],
            ['value' => '.example.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE],
            ['value' => 'example.com..', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE],
            ['value' => 'exa mple.com', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE],
            ['value' => 'google console', 'description' => Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE],
        ];

        foreach ($invalidValues as $value) {
            $this->assertFalse($validator->isValid($value['value']), "Expected invalid: {$value['value']}");
            $this->assertSame($value['description'], $validator->getDescription());
        }
    }

    public function testInvalidWildcard(): void
    {
        $validator = new Name(Record::TYPE_CNAME);

        $invalidValues = [
            'foo.*.com',
            'foo.*',
            '*foo.com',
            'f*o.com',
            '*a',
            'a*',
            '**',
            '*.*.example.com',
        ];

        foreach ($invalidValues as $value) {
            $this->assertFalse($validator->isValid($value), "Expected invalid: {$value}");
            $this->assertSame(Name::FAILURE_REASON_INVALID_WILDCARD, $validator->getDescription());
        }

        // '*..com' fails on the empty label left after the wildcard is stripped
        $this->assertFalse($validator->isValid('*..com'), 'Expected invalid: *..com');
        $this->assertSame(Name::FAILURE_REASON_INVALID_LABEL_CHARACTERS_WITH_UNDERSCORE, $validator->getDescription());
    }
}
