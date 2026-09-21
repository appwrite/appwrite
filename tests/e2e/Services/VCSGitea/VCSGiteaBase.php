<?php

namespace Tests\E2E\Services\VCSGitea;

use Utopia\System\System;

trait VCSGiteaBase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (empty(System::getEnv('_APP_VCS_GITEA_CLIENT_ID')) || empty(System::getEnv('_APP_VCS_GITEA_CLIENT_SECRET'))) {
            $this->markTestSkipped('Gitea VCS is not configured.');
        }
    }
}
