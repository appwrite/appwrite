<?php

namespace Tests\E2E\Services\VCS;

use Utopia\System\System;

trait VCSBase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY') === 'disabled') {
            $this->markTestSkipped('VCS is not enabled.');
        }
    }
}
