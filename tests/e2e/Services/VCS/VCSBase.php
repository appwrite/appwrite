<?php

namespace Tests\E2E\Services\VCS;

use Utopia\System\System;

trait VCSBase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessVcs($this->vcsProvider ?? 'github');
    }

    protected function skipUnlessVcs(string $provider): void
    {
        match ($provider) {
            'github' => System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY') === 'disabled'
                ? $this->markTestSkipped('GitHub VCS is not enabled.')
                : null,
            'gitea' => empty(System::getEnv('_APP_VCS_GITEA_ENDPOINT', ''))
                ? $this->markTestSkipped('Gitea VCS is not enabled.')
                : null,
            default => $this->markTestSkipped("VCS provider '{$provider}' is not enabled."),
        };
    }
}
