<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\DNS\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\DNS\Validator\CAA;

final class CAATest extends TestCase
{
    public function testValid(): void
    {
        $validator = new CAA();

        $validValues = [
            '0 issue "letsencrypt.org"',
            '128 issuewild "certainly.com;account=123456;validationmethods=dns-01"',
            '0 issuewild "certainly.com"',
            '0 iodef "mailto:security@example.com"',
            '0 issue ";"',
            '0 issue "certainly.com; validationmethods=dns-01"',
        ];

        foreach ($validValues as $value) {
            $this->assertTrue($validator->isValid($value), "Expected valid: {$value}");
        }
    }

    public function testInvalid(): void
    {
        $validator = new CAA();

        $invalidValues = [
            ['value' => 'issue "letsencrypt.org"', 'description' => CAA::FAILURE_REASON_INVALID_FORMAT],
            ['value' => '0 ""', 'description' => CAA::FAILURE_REASON_INVALID_FORMAT],
            ['value' => '256 issue "letsencrypt.org"', 'description' => CAA::FAILURE_REASON_INVALID_FLAGS],
            ['value' => '0 issue letsencrypt.org', 'description' => CAA::FAILURE_REASON_INVALID_VALUE],
            ['value' => '0 issue ""', 'description' => CAA::FAILURE_REASON_INVALID_VALUE],
        ];

        foreach ($invalidValues as $invalidValue) {
            $this->assertFalse($validator->isValid($invalidValue['value']), "Expected invalid: {$invalidValue['value']}");
            $this->assertSame($invalidValue['description'], $validator->getDescription());
        }
    }
}
