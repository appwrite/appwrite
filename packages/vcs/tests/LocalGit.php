<?php

declare(strict_types=1);

namespace Utopia\VCS\Tests;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Command;
use Utopia\VCS\Adapter\Git\GitHub;

/**
 * A Git adapter whose clone URL is a repository on disk, so the shared clone
 * command runs end to end without a provider.
 */
final class LocalGit extends GitHub
{
    public function __construct(private readonly string $repository)
    {
        parent::__construct(new Cache(new None()));
    }

    public function generateCloneCommand(string $owner, string $repositoryName, string $version, string $versionType, string $directory, string $rootDirectory): Command
    {
        return $this->cloneCommand('file://' . $this->repository, $version, $versionType, $directory, $rootDirectory);
    }
}
