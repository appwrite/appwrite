<?php

declare(strict_types=1);

namespace Tests\Unit\GraphQL\Rule;

use Appwrite\GraphQL\Rule\Complexity;
use GraphQL\Error\Error;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\QueryComplexity;
use PHPUnit\Framework\TestCase;

final class ComplexityTest extends TestCase
{
    private Schema $schema;

    protected function setUp(): void
    {
        $this->schema = BuildSchema::build('type Query { node: Node } type Node { id: String node: Node }');
    }

    public function testMatchesTheLibraryComplexity(): void
    {
        $document = Parser::parse(self::doubling(4));

        $this->assertSame(
            $this->messages(DocumentValidator::validate($this->schema, $document, [new QueryComplexity(10)])),
            $this->messages(DocumentValidator::validate($this->schema, $document, [new Complexity(10)])),
        );
    }

    public function testRepeatedFragmentSpreadsAreScoredOnce(): void
    {
        $document = Parser::parse(self::doubling(20));

        $start = \microtime(true);
        $errors = DocumentValidator::validate($this->schema, $document, [new Complexity(250)]);
        $elapsed = \microtime(true) - $start;

        $this->assertSame([QueryComplexity::maxQueryComplexityErrorMessage(250, 3 * 2 ** 20 - 1)], $this->messages($errors));
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
