<?php

namespace Tests\E2E\Services\VCSGitHub;

use Utopia\System\System;

trait VCSGitHubBase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY') === 'disabled') {
            $this->markTestSkipped('VCS is not enabled.');
        }
    }
}
