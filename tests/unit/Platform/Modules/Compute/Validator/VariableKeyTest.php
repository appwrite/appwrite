<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Compute\Validator;

use Appwrite\Platform\Modules\Compute\Validator\VariableKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariableKeyTest extends TestCase
{
    public static function endpointKeys(): \Iterator
    {
        yield 'plain' => ['MY_KEY', true];
        yield 'lowercase' => ['my_key', true];
        yield 'leading underscore' => ['_KEY', true];
        yield 'digits after first char' => ['KEY_2', true];
        yield 'single letter' => ['a', true];
        yield 'empty' => ['', false];
        yield 'leading digit' => ['9KEY', false];
        yield 'space' => ['MY KEY', false];
        yield 'hyphen' => ['MY-KEY', false];
        yield 'dot' => ['my.key', false];
        yield 'equals sign' => ['FOO=BAR', false];
        yield 'trailing tab' => ["MY_KEY\t", false];
        yield 'accented letter' => ['RÉSUMÉ_KEY', false];
        yield 'utf-16 bytes' => ["A\x00C\x00M\x00E", false];
    }

    #[DataProvider('endpointKeys')]
    public function testEndpointRule(string $key, bool $valid): void
    {
        $this->assertSame($valid, (new VariableKey())->isValid($key));
    }

    public function testEndpointRuleEnforcesMaxLength(): void
    {
        $validator = new VariableKey(4);

        $this->assertTrue($validator->isValid('ABCD'));
        $this->assertFalse($validator->isValid('ABCDE'));
    }

    public static function envVarNames(): \Iterator
    {
        yield 'plain' => ['MY_KEY', true];
        // Keys that predate the endpoint rule and still deploy fine.
        yield 'hyphen' => ['MY-VAR', true];
        yield 'dot' => ['my.env-name', true];
        yield 'leading dot' => ['.profile', true];
        yield 'empty' => ['', false];
        yield 'single dot' => ['.', false];
        yield 'double dot' => ['..', false];
        yield 'double dot prefix' => ['..FOO', false];
        yield 'leading digit' => ['9FOO', false];
        yield 'space' => ['MY VAR', false];
        yield 'equals sign' => ['FOO=BAR', false];
        yield 'trailing tab' => ["SOME_APP_SECRET\t", false];
        yield 'accented letter' => ['RÉSUMÉ_KEY', false];
        yield 'utf-16 bytes' => ["A\x00C\x00M\x00E", false];
    }

    #[DataProvider('envVarNames')]
    public function testKubernetesRule(string $key, bool $valid): void
    {
        $this->assertSame($valid, VariableKey::isEnvVarName($key));
    }
}
