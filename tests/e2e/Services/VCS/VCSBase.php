<?php

namespace Tests\E2E\Services\VCS;

use Utopia\App;

trait VCSBase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (App::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY') === 'disabled') {
            $this->markTestSkipped('VCS is not enabled.');
        }
    }
}
