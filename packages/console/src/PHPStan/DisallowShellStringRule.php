<?php

declare(strict_types=1);

namespace Utopia\Console\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Utopia\Console\Console;

/**
 * The two calls that take a whole command as one string and hand it to
 * /bin/sh: proc_open() and Console::execute(). Given argv or a Command they
 * never reach a shell, so proc_open() with argv stays allowed for a child
 * that needs the terminal.
 *
 * @implements Rule<CallLike>
 */
class DisallowShellStringRule implements Rule
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable()) {
            return [];
        }

        // Console and Command are where the shell is reached on everyone's behalf
        if (\str_starts_with($scope->getFile(), \dirname(__DIR__) . '/')) {
            return [];
        }

        $command = $node->getArgs()[0] ?? null;
        if ($command === null) {
            return [];
        }

        $type = $scope->getType($command->value);

        if ($node instanceof FuncCall && $this->isProcOpen($node, $scope) && !$type->isArray()->yes()) {
            return [
                RuleErrorBuilder::message('proc_open() given a string runs it through a shell. Pass argv, or build a Utopia\Console\Command and run it with Console::execute().')
                    ->identifier('function.disallowedShell')
                    ->build(),
            ];
        }

        if ($node instanceof StaticCall && $this->isConsoleExecute($node, $scope) && $type->isString()->yes()) {
            return [
                RuleErrorBuilder::message('Console::execute() runs a string through a shell. Pass a Utopia\Console\Command instead.')
                    ->identifier('staticMethod.disallowedShell')
                    ->build(),
            ];
        }

        return [];
    }

    private function isProcOpen(FuncCall $node, Scope $scope): bool
    {
        return $node->name instanceof Name
            && $this->reflectionProvider->hasFunction($node->name, $scope)
            && $this->reflectionProvider->getFunction($node->name, $scope)->getName() === 'proc_open';
    }

    private function isConsoleExecute(StaticCall $node, Scope $scope): bool
    {
        // Utopia\Console is the name Console had before it moved, still aliased
        return $node->name instanceof Identifier
            && $node->name->toLowerString() === 'execute'
            && $node->class instanceof Name
            && \in_array($scope->resolveName($node->class), [Console::class, \Utopia\Console::class], true);
    }
}
