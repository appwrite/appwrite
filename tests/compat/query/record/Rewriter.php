<?php

namespace Tests\Compat\Query\Record;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Rewrites a PHP test file so every call it makes on the library goes
 * through Rec, which records it. Calls on `$this` (the test's own helpers)
 * stay as they are: their bodies are rewritten too.
 */
final class Rewriter extends NodeVisitorAbstract
{
    private const REC = 'Tests\\Compat\\Query\\Record\\Rec';

    /** @var list<Node> */
    private array $skip = [];

    private int $constDepth = 0;

    /** @var list<bool> whether each enclosing class implements a library interface */
    private array $classes = [];

    public function __construct(private bool $soft = false)
    {
    }

    public static function rewrite(string $code, bool $soft = false): string
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new self($soft));
        $ast = $traverser->traverse($ast);

        return (new Standard())->prettyPrintFile($ast);
    }

    public function enterNode(Node $node)
    {
        // Constant expressions cannot hold calls.
        if ($node instanceof Node\Param || $node instanceof Stmt\ClassConst || $node instanceof Node\PropertyItem
            || $node instanceof Node\Attribute || $node instanceof Stmt\Const_ || $node instanceof Node\Const_
            || $node instanceof Node\StaticVar || $node instanceof Stmt\EnumCase) {
            $this->constDepth++;
        }
        // Writes: the target of an assignment, unset, isset, empty.
        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignOp || $node instanceof Expr\AssignRef) {
            $this->markWrite($node->var);
        }
        if ($node instanceof Expr\PreInc || $node instanceof Expr\PreDec || $node instanceof Expr\PostInc || $node instanceof Expr\PostDec) {
            $this->markWrite($node->var);
        }
        if ($node instanceof Stmt\Unset_ || $node instanceof Expr\Isset_) {
            foreach ($node->vars as $var) {
                $this->markWrite($var);
            }
        }
        if ($node instanceof Expr\Empty_) {
            $this->markWrite($node->expr);
        }
        if ($node instanceof Stmt\Foreach_) {
            $this->markWrite($node->valueVar);
            if ($node->keyVar !== null) {
                $this->markWrite($node->keyVar);
            }
        }
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Trait_) {
            $this->classes[] = $node instanceof Stmt\Class_ && $this->implementsLibrary($node);
        }

        return null;
    }

    private function implementsLibrary(Stmt\Class_ $class): bool
    {
        foreach ($class->implements as $name) {
            if (Rec::isLibraryClass($name->toString())) {
                return true;
            }
        }

        return false;
    }

    private function markWrite(Node $node): void
    {
        $node->setAttribute('write', true);
        if ($node instanceof Expr\ArrayDimFetch || $node instanceof Expr\PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
            $this->markWrite($node->var);
        }
        if ($node instanceof Expr\List_ || $node instanceof Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item !== null) {
                    $this->markWrite($item->value);
                }
            }
        }
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Param || $node instanceof Stmt\ClassConst || $node instanceof Node\PropertyItem
            || $node instanceof Node\Attribute || $node instanceof Stmt\Const_ || $node instanceof Node\Const_
            || $node instanceof Node\StaticVar || $node instanceof Stmt\EnumCase) {
            $this->constDepth--;

            return null;
        }
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Trait_) {
            \array_pop($this->classes);

            return null;
        }
        // Integration tests: a statement failing for want of a database must
        // not stop the statements after it from building their queries.
        if ($this->soft && $node instanceof Stmt\ClassMethod && $node->stmts !== null && !$node->isStatic()) {
            $node->stmts = \array_map(
                fn (Stmt $stmt) => $stmt instanceof Stmt\Return_ || $stmt instanceof Stmt\Expression || $stmt instanceof Stmt\Foreach_ || $stmt instanceof Stmt\If_ || $stmt instanceof Stmt\For_
                    ? new Stmt\TryCatch([$stmt], [new Stmt\Catch_([new Name\FullyQualified('Throwable')], null, [])])
                    : $stmt,
                $node->stmts,
            );

            return $node;
        }
        if ($node instanceof Stmt\ClassMethod && $this->classes !== [] && \end($this->classes) && $node->isPublic() && !$node->isStatic()
            && $node->stmts !== null && $node->name->toString() !== '__construct') {
            return $this->instrument($node);
        }
        if ($this->constDepth > 0) {
            return null;
        }
        // Interpolated parts may have become calls: concatenate instead.
        if ($node instanceof Node\Scalar\InterpolatedString) {
            $result = null;
            foreach ($node->parts as $part) {
                $expr = $part instanceof Node\InterpolatedStringPart ? new String_($part->value) : new Expr\Cast\String_($part);
                $result = $result === null ? $expr : new Expr\BinaryOp\Concat($result, $expr);
            }

            return $result ?? new String_('');
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->isFirstClassCallable()
            && Rec::isLibraryClass($node->class->toString()) && $node->name instanceof Node\Identifier) {
            // Query::join(...): a closure that records its calls.
            return $this->spreadClosure($this->rec('s', [$this->classConst($node->class), new String_($node->name->toString()), new Expr\Variable('__args')]));
        }
        if ($node instanceof Expr\MethodCall) {
            if ($node->isFirstClassCallable()) {
                if ($node->var instanceof Expr\Variable && $node->var->name === 'this') {
                    return null;
                }

                return $this->spreadClosure($this->rec('m', [$node->var, $this->name($node->name), new Expr\Variable('__args')]));
            }
            if ($node->var instanceof Expr\Variable && $node->var->name === 'this') {
                return $this->thisCall($node);
            }

            return $this->rec('m', [$node->var, $this->name($node->name), $this->argArray($node->args)]);
        }
        if ($node instanceof Expr\NullsafeMethodCall) {
            if ($node->isFirstClassCallable()) {
                return null;
            }

            return $this->rec('nm', [$node->var, $this->name($node->name), $this->argArray($node->args)]);
        }
        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && !$node->isFirstClassCallable()) {
            $class = $node->class->toString();
            if (\in_array(\strtolower($class), ['self', 'static', 'parent'], true) || !Rec::isLibraryClass($class)) {
                return null;
            }

            return $this->rec('s', [$this->classConst($node->class), $this->name($node->name), $this->argArray($node->args)]);
        }
        if ($node instanceof Expr\New_ && $node->class instanceof Name) {
            $class = $node->class->toString();
            if (\in_array(\strtolower($class), ['self', 'static', 'parent'], true) || !Rec::isLibraryClass($class)) {
                return null;
            }

            return $this->rec('n', [$this->classConst($node->class), $this->argArray($node->args)]);
        }
        if ($node instanceof Expr\New_ && $node->class instanceof Expr) {
            return $this->rec('n', [$node->class, $this->argArray($node->args)]);
        }
        if ($node instanceof Expr\PropertyFetch && !$node->getAttribute('write', false)) {
            if ($node->var instanceof Expr\Variable && $node->var->name === 'this') {
                return null;
            }
            if (!$node->name instanceof Node\Identifier) {
                return null;
            }

            return $this->rec('g', [$node->var, new String_($node->name->toString())]);
        }
        if ($node instanceof Expr\NullsafePropertyFetch && !$node->getAttribute('write', false) && $node->name instanceof Node\Identifier) {
            return $this->rec('ng', [$node->var, new String_($node->name->toString())]);
        }
        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name && $node->name instanceof Node\Identifier
            && $node->name->toString() !== 'class') {
            $class = $node->class->toString();
            if (\in_array(\strtolower($class), ['self', 'static', 'parent'], true) || !Rec::isLibraryClass($class)) {
                return null;
            }

            return $this->rec('c', [$this->classConst($node->class), new String_($node->name->toString())]);
        }
        if ($node instanceof Expr\Instanceof_ && $node->class instanceof Name) {
            $class = $node->class->toString();
            if (!Rec::isLibraryClass($class)) {
                return null;
            }

            return $this->rec('is', [$node->expr, $this->classConst($node->class)]);
        }
        if ($node instanceof Expr\Clone_) {
            return $this->rec('cl', [$node->expr]);
        }
        if ($node instanceof Expr\FuncCall && $node->name instanceof Name) {
            $name = \strtolower(\ltrim($node->name->toString(), '\\'));
            if ($name === 'class_implements' && \count($node->args) === 1) {
                return $this->rec('impls', [$node->args[0]->value]);
            }
            // Recordings are deterministic: uniqid() and rand() replay the same values.
            if ($name === 'uniqid' || $name === 'rand' || $name === 'mt_rand') {
                return $this->rec('fixed', [new String_($name), $this->argArray($node->args)]);
            }
            if ($name === 'get_class' && \count($node->args) === 1) {
                return $this->rec('cls', [$node->args[0]->value]);
            }
            if ($name === 'assert' && $this->soft) {
                return $this->rec('soft', \array_map(fn (Arg $a) => $a->value, $node->args));
            }
        }

        return null;
    }

    private function thisCall(Expr\MethodCall $node): ?Node
    {
        if (!$node->name instanceof Node\Identifier) {
            return null;
        }
        $name = $node->name->toString();
        $args = $node->args;
        // Integration tests assert on database results, which recording does
        // not have: keep evaluating the arguments (they read the library),
        // drop the assertion.
        if ($this->soft && (\str_starts_with($name, 'assert') || \str_starts_with($name, 'expect') || $name === 'fail' || \str_starts_with($name, 'markTest'))) {
            return $this->rec('soft', \array_map(fn (Arg $a) => $a->value, \array_filter($args, fn ($a) => $a instanceof Arg)));
        }
        if ($name === 'assertInstanceOf' || $name === 'assertNotInstanceOf') {
            $check = $this->rec('is', [$args[1]->value, $args[0]->value]);
            $node = new Expr\MethodCall(
                $node->var,
                $name === 'assertInstanceOf' ? 'assertTrue' : 'assertFalse',
                \array_merge([new Arg($check)], \array_slice($args, 2)),
            );
        } elseif (($name === 'assertSame' || $name === 'assertNotSame') && \count($args) >= 2) {
            $pair = $this->rec('pair', [$args[0]->value, $args[1]->value]);
            $node = new Expr\MethodCall(
                $node->var,
                $name,
                \array_merge([new Arg($pair), new Arg($this->rec('second', []))], \array_slice($args, 2)),
            );
        }
        return $node;
    }

    /**
     * A public method of a test class implementing library interfaces: its
     * body runs through Rec::implCall, so the library calling it is recorded.
     */
    private function instrument(Stmt\ClassMethod $method): Stmt\ClassMethod
    {
        $uses = [];
        foreach ($method->params as $param) {
            if ($param->var instanceof Expr\Variable && \is_string($param->var->name)) {
                $uses[] = new Node\ClosureUse(new Expr\Variable($param->var->name), $param->byRef);
            }
        }
        $closure = new Expr\Closure(['stmts' => $method->stmts, 'uses' => $uses, 'byRef' => $method->byRef]);
        $call = $this->rec('implCall', [
            new Expr\Variable('this'),
            new String_($method->name->toString()),
            new Expr\FuncCall(new Name\FullyQualified('func_get_args')),
            $closure,
        ]);
        $void = $method->returnType instanceof Node\Identifier && \in_array($method->returnType->toLowerString(), ['void', 'never'], true);
        $method->stmts = $void ? [new Stmt\Expression($call)] : [new Stmt\Return_($call)];

        return $method;
    }

    private function spreadClosure(Expr $body): Expr\ArrowFunction
    {
        return new Expr\ArrowFunction([
            'params' => [new Node\Param(new Expr\Variable('__args'), null, null, false, true)],
            'expr' => $body,
        ]);
    }

    /**
     * @param list<Expr> $args
     */
    private function rec(string $method, array $args): Expr\StaticCall
    {
        return new Expr\StaticCall(new Name\FullyQualified(self::REC), $method, \array_map(fn (Expr $e) => new Arg($e), $args));
    }

    private function classConst(Name $name): Expr
    {
        return new Expr\ClassConstFetch(new Name\FullyQualified(\ltrim($name->toString(), '\\')), 'class');
    }

    private function name(Node $name): Expr
    {
        return $name instanceof Node\Identifier ? new String_($name->toString()) : $name;
    }

    /**
     * Call arguments as an array: named arguments get their name as key,
     * spreads stay spreads.
     *
     * @param array<Arg|Node\VariadicPlaceholder> $args
     */
    private function argArray(array $args): Expr\Array_
    {
        $items = [];
        foreach ($args as $arg) {
            if (!$arg instanceof Arg) {
                continue;
            }
            $key = $arg->name !== null ? new String_($arg->name->toString()) : null;
            $items[] = new Node\ArrayItem($arg->value, $key, $arg->byRef, [], $arg->unpack);
        }

        return new Expr\Array_($items, ['kind' => Expr\Array_::KIND_SHORT]);
    }
}
