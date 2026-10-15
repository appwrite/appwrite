<?php

namespace Tests\Compat\Database\Recorder;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Rewrites a unit test file of the library so that what its code does with
 * the library goes through the recorder (Recorder::create, ::callStatic,
 * ::callObject): `new <library class>(...)`, `<library class>::method(...)`
 * and `$expr->method(...)` (on any object but `$this`; the recorder records
 * the call only when the object is the library's). Constant expressions
 * (parameter defaults, constants, property defaults, attributes, static
 * variables) keep their `new`. A PDO the test builds (`new PDO(...)`,
 * `new \Utopia\Database\PDO(...)`) is created through the recorder too, so
 * it can name its DSN.
 */
final class Rewriter extends NodeVisitorAbstract
{
    private const RECORDER = '\\Tests\\Compat\\Database\\Recorder\\Recorder';

    /** @var array<int, true> nodes inside constant expressions */
    private array $constant = [];

    public static function rewrite(string $source): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($source) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => true]));
        $ast = $traverser->traverse($ast);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new self());

        return (new Standard())->prettyPrintFile($traverser->traverse($ast));
    }

    public static function library(string $class): bool
    {
        return str_starts_with($class, 'Utopia\\Database\\') || str_starts_with($class, 'Utopia\\Query\\');
    }

    public function enterNode(Node $node)
    {
        $constants = match (true) {
            $node instanceof Node\Param => [$node->default],
            $node instanceof Node\Const_ => [$node->value],
            $node instanceof Node\PropertyItem => [$node->default],
            $node instanceof Node\StaticVar => [$node->default],
            $node instanceof Node\Attribute => $node->args,
            default => [],
        };
        foreach ($constants as $constant) {
            if ($constant instanceof Node) {
                $this->mark($constant);
            }
        }

        return;
    }

    private function mark(Node $node): void
    {
        $this->constant[spl_object_id($node)] = true;
        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->{$name};
            foreach (\is_array($sub) ? $sub : [$sub] as $child) {
                if ($child instanceof Node) {
                    $this->mark($child);
                }
            }
        }
    }

    public function leaveNode(Node $node): ?Node
    {
        if (isset($this->constant[spl_object_id($node)])) {
            return null;
        }
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name\FullyQualified) {
            $class = $node->class->toString();
            if (!self::library($class) && $class !== 'PDO') {
                return null;
            }
            $args = $this->arguments($node->args);

            return $args === null ? null : $this->recorder('create', [new Node\Scalar\String_($class), $args]);
        }
        // `new $class(...)`: the recorder decides at run time.
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Expr) {
            $args = $this->arguments($node->args);

            return $args === null ? null : $this->recorder('create', [$node->class, $args]);
        }
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name\FullyQualified && $node->name instanceof Node\Identifier) {
            $class = $node->class->toString();
            if (!self::library($class)) {
                return null;
            }
            $args = $this->arguments($node->args);

            return $args === null ? null : $this->recorder('callStatic', [new Node\Scalar\String_($class), new Node\Scalar\String_($node->name->toString()), $args]);
        }
        if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
            if ($node->var instanceof Node\Expr\Variable && $node->var->name === 'this') {
                return null;
            }
            $args = $this->arguments($node->args);
            if ($args === null) {
                return null;
            }

            return $this->recorder($node instanceof Node\Expr\NullsafeMethodCall ? 'callNullsafe' : 'callObject', [$node->var, new Node\Scalar\String_($node->name->toString()), $args]);
        }

        return null;
    }

    /**
     * The arguments as one array literal (positional, named and spread), or
     * null when they cannot be (by reference, a first-class callable).
     *
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function arguments(array $args): ?Node\Expr\Array_
    {
        $items = [];
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->byRef) {
                return null;
            }
            $items[] = new Node\ArrayItem($arg->value, $arg->name === null ? null : new Node\Scalar\String_($arg->name->toString()), false, [], $arg->unpack);
        }

        return new Node\Expr\Array_($items, ['kind' => Node\Expr\Array_::KIND_SHORT]);
    }

    /**
     * @param list<Node\Expr> $args
     */
    private function recorder(string $method, array $args): Node\Expr\StaticCall
    {
        return new Node\Expr\StaticCall(new Node\Name\FullyQualified(ltrim(self::RECORDER, '\\')), $method, array_map(fn (Node\Expr $e) => new Node\Arg($e), $args));
    }
}
