<?php

namespace Appwrite\GraphQL\Rule;

use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\SelectionNode;
use GraphQL\Validator\QueryValidationContext;
use GraphQL\Validator\Rules\QueryComplexity;

/**
 * Query complexity that scores each fragment once, so a fragment spread many times costs one walk instead of one per spread.
 */
class Complexity extends QueryComplexity
{
    /**
     * @var array<string, int>
     */
    private array $scores = [];

    public function getVisitor(QueryValidationContext $context): array
    {
        $this->scores = [];

        return parent::getVisitor($context);
    }

    protected function nodeComplexity(SelectionNode $node): int
    {
        if (!$node instanceof FragmentSpreadNode) {
            return parent::nodeComplexity($node);
        }

        $fragment = $this->getFragment($node);
        if ($fragment === null) {
            return 0;
        }

        return $this->scores[$fragment->name->value] ??= $this->fieldComplexity($fragment->selectionSet);
    }
}
