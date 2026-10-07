<?php

namespace Appwrite\Config\Adapter;

use Appwrite\Config\Adapter;
use Appwrite\Config\Exception\Load;
use Appwrite\Config\Exception\Parse;

class PHP extends Adapter
{
    public function parse(string $contents): array
    {
        throw new Parse('PHP config only supports loading, not parsing.');
    }

    /**
     * Including the file parses and loads it in one step.
     */
    public function load(string $path): array
    {
        $contents = include $path;

        if (! \is_array($contents)) {
            throw new Load('PHP config did not return an array: ' . $path);
        }

        return $contents;
    }
}
