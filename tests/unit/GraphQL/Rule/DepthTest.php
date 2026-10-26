<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL\Rule;

use Appwrite\GraphQL\Rule\Depth;
use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryDepth;
use PHPUnit\Framework\TestCase;

final class DepthTest extends TestCase
{
    private Schema $schema;

    protected function setUp(): void
    {
        $this->schema = BuildSchema::build('type Query { node: Node } type Node { id: String node: Node }');
    }

    public function testMatchesTheLibraryDepth(): void
    {
        $document = Parser::parse(self::doubling(4));

        $this->assertSame(
            $this->messages(DocumentValidator::validate($this->schema, $document, [new QueryDepth(2)])),
            $this->messages(DocumentValidator::validate($this->schema, $document, [new Depth(2)])),
        );
    }

    public function testMeasuresFragmentsSpreadAtDifferentDepths(): void
    {
        $document = Parser::parse('{ node { ...Leaf node { node { ...Leaf } } } } fragment Leaf on Node { node { id } }');

        $this->assertSame([QueryDepth::maxQueryDepthErrorMessage(2, 3)], $this->messages(DocumentValidator::validate($this->schema, $document, [new Depth(2)])));
        $this->assertSame([], $this->messages(DocumentValidator::validate($this->schema, $document, [new Depth(3)])));
    }

    public function testRepeatedFragmentSpreadsAreMeasuredOnce(): void
    {
        $document = Parser::parse(self::doubling(20));

        $start = \microtime(true);
        $errors = DocumentValidator::validate($this->schema, $document, [new Depth(3)]);
        $elapsed = \microtime(true) - $start;

        $this->assertSame([QueryDepth::maxQueryDepthErrorMessage(3, 20)], $this->messages($errors));
        $this->assertLessThan(1, $elapsed);
    }

    /**
     * A query whose fragment at each level spreads the one below it twice, so its expanded tree doubles per level.
     */
    private static function doubling(int $levels): string
    {
        $fragments = ['fragment F0 on Node { id }'];
        for ($level = 1; $level <= $levels; $level++) {
            $previous = $level - 1;
            $fragments[] = "fragment F{$level} on Node { a: node { ...F{$previous} } b: node { ...F{$previous} } }";
        }

        return "{ node { ...F{$levels} } } " . \implode(' ', $fragments);
    }

    /**
     * @param array<Error> $errors
     * @return array<string>
     */
    private function messages(array $errors): array
    {
        return \array_map(static fn (Error $error): string => $error->getMessage(), $errors);
    }
}
