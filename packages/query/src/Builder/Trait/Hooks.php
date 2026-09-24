<?php

namespace Utopia\Query\Builder\Trait;

use Utopia\Query\Hook;
use Utopia\Query\Hook\Attribute;
use Utopia\Query\Hook\Filter;
use Utopia\Query\Hook\Join\Filter as JoinFilter;

trait Hooks
{
    #[\Override]
    public function addHook(Hook $hook): static
    {
        if ($hook instanceof Filter) {
            $this->filterHooks[] = $hook;
        }
        if ($hook instanceof Attribute) {
            $this->attributeHooks[] = $hook;
        }
        if ($hook instanceof JoinFilter) {
            $this->joinFilterHooks[] = $hook;
        }

        return $this;
    }
}
