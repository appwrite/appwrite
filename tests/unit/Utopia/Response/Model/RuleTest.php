<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model\Rule;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class RuleTest extends TestCase
{
    public function test_exposes_smtp_rule_contract(): void
    {
        $rule = (new Rule())->filter(new Document([
            'protocol' => 'smtp',
            'verificationToken' => 'verification-token',
        ]));

        $this->assertSame('smtp', $rule->getAttribute('protocol'));
        $this->assertSame('verification-token', $rule->getAttribute('verificationToken'));
    }

    public function test_http_is_the_default_protocol(): void
    {
        $rules = (new Rule())->getRules();

        $this->assertSame('http', $rules['protocol']['default']);
    }
}
