<?php

declare(strict_types=1);

namespace Utopia\Console\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Utopia\Console\PHPStan\DisallowShellStringRule;

/**
 * @extends RuleTestCase<DisallowShellStringRule>
 */
final class DisallowShellStringRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DisallowShellStringRule(self::createReflectionProvider());
    }

    public function testReportsAWholeCommandHandedOverAsAString(): void
    {
        $this->analyse([__DIR__ . '/resources/shell.php'], [
            ['proc_open() given a string runs it through a shell. Pass argv, or build a Utopia\Console\Command and run it with Console::execute().', 25],
            ['Console::execute() runs a string through a shell. Pass a Utopia\Console\Command instead.', 26],
            ['Console::execute() runs a string through a shell. Pass a Utopia\Console\Command instead.', 27],
            ['proc_open() given a string runs it through a shell. Pass argv, or build a Utopia\Console\Command and run it with Console::execute().', 28],
            ['Console::execute() runs a string through a shell. Pass a Utopia\Console\Command instead.', 29],
            ['Console::execute() runs a string through a shell. Pass a Utopia\Console\Command instead.', 30],
        ]);
    }
}
