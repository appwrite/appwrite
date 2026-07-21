<?php

namespace Appwrite\Vcs;

/**
 * Implemented by OAuth2 providers that can create a repository on behalf of
 * a personal VCS installation. Narrow on purpose -- it's the only capability
 * call sites need to check for beyond the base OAuth2 contract; construction
 * from environment variables is the Factory's job, not the provider's.
 */
interface RepositoryOAuth2
{
    public function createRepository(string $accessToken, string $repositoryName, bool $private): array;
}
