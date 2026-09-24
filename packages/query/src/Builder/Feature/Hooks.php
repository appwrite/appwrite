<?php

namespace Utopia\Query\Builder\Feature;

use Utopia\Query\Hook;

interface Hooks
{
    public function addHook(Hook $hook): static;
}
