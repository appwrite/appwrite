<?php

declare(strict_types=1);

namespace Utopia\Tests;

use RuntimeException;

/**
 * Where the package's compose services listen on the host. Ports are offset so
 * they never collide with a local stack — keep them in sync with
 * docker-compose.yml.
 */
final class Services
{
    public const string GITEA_URL = 'http://127.0.0.1:13000';

    public const string FORGEJO_URL = 'http://127.0.0.1:13001';

    public const string GOGS_URL = 'http://127.0.0.1:13002';

    public const string GITLAB_URL = 'http://127.0.0.1:13003';

    /**
     * Where a test reads the deliveries back from.
     */
    public const string CATCHER_URL = 'http://127.0.0.1:15000';

    /**
     * Where a provider sends them: the catcher is a container of the same
     * network, so it is reachable there under its service name.
     */
    public const string CATCHER_INTERNAL_URL = 'http://request-catcher:5000';

    /**
     * Access token the provider's bootstrap minted, dropped into tests/.tokens.
     */
    public static function token(string $provider): string
    {
        $path = __DIR__ . "/.tokens/{$provider}.txt";
        $token = is_file($path) ? trim((string) file_get_contents($path)) : '';

        if ($token === '') {
            throw new RuntimeException("No {$provider} token in {$path} — is the compose stack up?");
        }

        return $token;
    }
}
