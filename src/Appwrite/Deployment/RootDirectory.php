<?php

namespace Appwrite\Deployment;

use InvalidArgumentException;

/**
 * A repository-relative directory a deployment builds from, guaranteed not to escape the repository.
 */
final readonly class RootDirectory
{
    public string $path;

    /**
     * @throws InvalidArgumentException when the path contains a ".." segment
     */
    public function __construct(string $path)
    {
        $path = \rtrim($path, '/');
        $path = \ltrim($path, '.');
        $path = \ltrim($path, '/');

        if (\in_array('..', \explode('/', $path), true)) {
            throw new InvalidArgumentException('Invalid root directory');
        }

        $this->path = $path;
    }
}
