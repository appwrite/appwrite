<?php

namespace Tests\E2E\Services\VCS;

use Utopia\System\System;

trait VCSBase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessVcs($this->getVcsProvider());
    }

    protected function getVcsProvider(): string
    {
        return 'github';
    }

    protected function skipUnlessVcs(string $provider): void
    {
        match ($provider) {
            'github' => System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY') === 'disabled'
                ? $this->markTestSkipped('GitHub VCS is not enabled.')
                : null,
            'gitea' => $this->isGiteaUnreachable()
                ? $this->markTestSkipped('Gitea VCS is not enabled.')
                : null,
            default => $this->markTestSkipped("VCS provider '{$provider}' is not enabled."),
        };
    }

    /**
     * `.env` always sets a non-empty _APP_VCS_GITEA_ENDPOINT default, so an
     * empty() check alone can't tell whether the `vcs` compose profile (and
     * therefore the Gitea container) is actually running -- probe the
     * endpoint itself instead.
     */
    private function isGiteaUnreachable(): bool
    {
        $endpoint = System::getEnv('_APP_VCS_GITEA_ENDPOINT', '');

        if (empty($endpoint)) {
            return true;
        }

        $host = \parse_url($endpoint, PHP_URL_HOST);
        $port = \parse_url($endpoint, PHP_URL_PORT) ?? (\parse_url($endpoint, PHP_URL_SCHEME) === 'https' ? 443 : 80);

        if (empty($host)) {
            return true;
        }

        $connection = @\fsockopen($host, $port, $errno, $errstr, 1);

        if ($connection === false) {
            return true;
        }

        \fclose($connection);
        return false;
    }
}
