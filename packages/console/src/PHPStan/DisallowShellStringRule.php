<?php

declare(strict_types=1);

namespace Utopia\Console\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
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

        if ($node instanceof FuncCall && $this->isProcOpen($node, $scope)) {
            $command = $this->argument($node, 'command');
            if (!$command instanceof Arg || $scope->getType($command->value)->isArray()->yes()) {
                return [];
            }

            return [
                RuleErrorBuilder::message('proc_open() given a string runs it through a shell. Pass argv, or build a Utopia\Console\Command and run it with Console::execute().')
                    ->identifier('function.disallowedShell')
                    ->build(),
            ];
        }

        if ($node instanceof StaticCall && $this->isConsoleExecute($node, $scope)) {
            // A union such as Command|string reaches the shell whenever it holds the string
            $command = $this->argument($node, 'cmd');
            if (!$command instanceof Arg || $scope->getType($command->value)->isString()->no()) {
                return [];
            }

            return [
                RuleErrorBuilder::message('Console::execute() runs a string through a shell. Pass a Utopia\Console\Command instead.')
                    ->identifier('staticMethod.disallowedShell')
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * The argument a call passes as $parameter: named, which PHP allows in any
     * order, or else the first one written in place.
     */
    private function argument(CallLike $node, string $parameter): ?Arg
    {
        foreach ($node->getArgs() as $argument) {
            if ($argument->name?->toString() === $parameter) {
                return $argument;
            }
        }

        $first = $node->getArgs()[0] ?? null;

        return $first instanceof Arg && !$first->name instanceof Identifier && !$first->unpack ? $first : null;
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
