<?php

namespace Appwrite\Vcs;

use Appwrite\Auth\OAuth2;

/**
 * Implemented by OAuth2 providers usable as a VCS installation's token-refresh
 * client. Unlike login-only OAuth2 providers (constructed per-project from
 * console-configured credentials), these are server-configured and can build
 * themselves from environment variables.
 */
interface EnvOAuth2
{
    public static function fromEnv(): OAuth2&EnvOAuth2;

    public function createRepository(string $accessToken, string $repositoryName, bool $private): array;
}
