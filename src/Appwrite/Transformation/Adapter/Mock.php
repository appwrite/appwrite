<?php

namespace Appwrite\Transformation\Adapter;

use Appwrite\Transformation\Adapter;

class Mock extends Adapter
{
    /**
     * @param array<mixed> $traits Mock traits
     */
    public function isValid(array $traits): bool
    {
        if ($traits['mock'] === true) {
            return true;
        }

        return false;
    }

    public function transform(): void
    {
        $this->output = $this->input;
        $this->output = "Mock: " . $this->output;
    }
}
