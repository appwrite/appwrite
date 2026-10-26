<?php

namespace Appwrite\GraphQL\Rule;

use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\Node;
use GraphQL\Validator\QueryValidationContext;
use GraphQL\Validator\Rules\QueryDepth;

/**
 * Query depth that measures each fragment once, so a fragment spread many times costs one walk instead of one per spread.
 */
class Depth extends QueryDepth
{
    /**
     * @var array<string, int> Deepest field level inside each fragment, relative to where it is spread; -1 when it has no nested selections
     */
    private array $heights = [];

    public function getVisitor(QueryValidationContext $context): array
    {
        $this->heights = [];

        return parent::getVisitor($context);
    }

    protected function nodeDepth(Node $node, int $depth = 0, int $maxDepth = 0): int
    {
        if (!$node instanceof FragmentSpreadNode) {
            return parent::nodeDepth($node, $depth, $maxDepth);
        }

        $fragment = $this->getFragment($node);
        if ($fragment === null) {
            return $maxDepth;
        }

        $name = $fragment->name->value;
        if (isset($this->calculatedFragments[$name])) {
            return $this->maxQueryDepth + 1;
        }

        if (!isset($this->heights[$name])) {
            $this->calculatedFragments[$name] = true;
            $this->heights[$name] = $this->fieldDepth($fragment, 0, -1);
            unset($this->calculatedFragments[$name]);
        }

        $height = $this->heights[$name];
        if ($height < 0) {
            return $maxDepth;
        }

        return \max($maxDepth, $depth + $height);
    }
}
