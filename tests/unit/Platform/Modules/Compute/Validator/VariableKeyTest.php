<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Compute\Validator;

use Appwrite\Platform\Modules\Compute\Validator\VariableKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VariableKeyTest extends TestCase
{
    public static function endpointKeys(): array
    {
        return [
            'plain' => ['MY_KEY', true],
            'lowercase' => ['my_key', true],
            'leading underscore' => ['_KEY', true],
            'digits after first char' => ['KEY_2', true],
            'single letter' => ['a', true],

            'empty' => ['', false],
            'leading digit' => ['9KEY', false],
            'space' => ['MY KEY', false],
            'hyphen' => ['MY-KEY', false],
            'dot' => ['my.key', false],
            'equals sign' => ['FOO=BAR', false],
            'trailing tab' => ["MY_KEY\t", false],
            'accented letter' => ['RÉSUMÉ_KEY', false],
            'utf-16 bytes' => ["A\x00C\x00M\x00E", false],
        ];
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

    public static function envVarNames(): array
    {
        return [
            'plain' => ['MY_KEY', true],
            // Keys that predate the endpoint rule and still deploy fine.
            'hyphen' => ['MY-VAR', true],
            'dot' => ['my.env-name', true],
            'leading dot' => ['.profile', true],

            'empty' => ['', false],
            'single dot' => ['.', false],
            'double dot' => ['..', false],
            'double dot prefix' => ['..FOO', false],
            'leading digit' => ['9FOO', false],
            'space' => ['MY VAR', false],
            'equals sign' => ['FOO=BAR', false],
            'trailing tab' => ["SOME_APP_SECRET\t", false],
            'accented letter' => ['RÉSUMÉ_KEY', false],
            'utf-16 bytes' => ["A\x00C\x00M\x00E", false],
        ];
    }

    #[DataProvider('envVarNames')]
    public function testKubernetesRule(string $key, bool $valid): void
    {
        $this->assertSame($valid, VariableKey::isEnvVarName($key));
    }
}
