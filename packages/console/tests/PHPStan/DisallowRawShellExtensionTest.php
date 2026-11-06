<?php

declare(strict_types=1);

namespace Utopia\Console\Tests\PHPStan;

use PHPStan\Rules\RestrictedUsage\RestrictedFunctionCallableUsageRule;
use PHPStan\Rules\RestrictedUsage\RestrictedFunctionUsageRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * PHPStan applies a restricted-function extension through its own rules, one
 * for calls and one for first-class callables, so each is checked here.
 *
 * @extends RuleTestCase<RestrictedFunctionUsageRule|RestrictedFunctionCallableUsageRule>
 */
final class DisallowRawShellExtensionTest extends RuleTestCase
{
    private const string MESSAGE = '() reaches a shell. Build the command with Utopia\Console\Command and run it with Console::execute().';

    /** @var class-string<RestrictedFunctionUsageRule|RestrictedFunctionCallableUsageRule> */
    private string $rule = RestrictedFunctionUsageRule::class;

    protected function getRule(): Rule
    {
        return self::getContainer()->getByType($this->rule);
    }

    /**
     * @return list<string>
     */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/resources/extension.neon'];
    }

    public function testReportsEveryFunctionThatReachesAShell(): void
    {
        $this->analyse([__DIR__ . '/resources/shell.php'], [
            ['exec' . self::MESSAGE, 17],
            ['shell_exec' . self::MESSAGE, 18],
            ['system' . self::MESSAGE, 19],
            ['passthru' . self::MESSAGE, 20],
            ['popen' . self::MESSAGE, 21],
            ['escapeshellarg' . self::MESSAGE, 22],
            ['escapeshellcmd' . self::MESSAGE, 23],
        ]);
    }

    public function testReportsAFirstClassCallableToOne(): void
    {
        $this->rule = RestrictedFunctionCallableUsageRule::class;

        $this->analyse([__DIR__ . '/resources/shell.php'], [
            ['escapeshellarg' . self::MESSAGE, 24],
        ]);
    }
}
