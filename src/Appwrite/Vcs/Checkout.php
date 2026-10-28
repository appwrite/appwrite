<?php

namespace Appwrite\Vcs;

use Appwrite\Vcs\Checkout\Exception;

final readonly class Checkout
{
    public function __construct(private string $path)
    {
    }

    /**
     * Resolves a directory inside the checkout, refusing symbolic links and
     * anything that resolves outside it.
     *
     * @throws Exception
     */
    public function resolve(string $directory, bool $create = false): string
    {
        $root = \realpath($this->path);
        if ($root === false) {
            throw new Exception('Repository checkout is missing.');
        }

        $path = $root;
        foreach (\explode('/', $directory) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new Exception('Root directory must stay inside the repository.');
            }

            $path .= '/' . $segment;
            if (\is_link($path)) {
                throw new Exception('Root directory "' . $directory . '" must not be a symbolic link.');
            }
            if (!\file_exists($path) && (!$create || !\mkdir($path))) {
                throw new Exception('Root directory "' . $directory . '" not found in the repository.');
            }
            if (!\is_dir($path)) {
                throw new Exception('Root directory "' . $directory . '" must be a directory.');
            }
        }

        if (\realpath($path) !== $path) {
            throw new Exception('Root directory must stay inside the repository.');
        }

        return $path;
    }
}
