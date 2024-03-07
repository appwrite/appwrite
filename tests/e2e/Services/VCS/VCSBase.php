<?php

namespace Tests\E2E\Services\VCS;

use Utopia\Http\Http;

trait VCSBase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (Http::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY') === 'disabled') {
            $this->markTestSkipped('VCS is not enabled.');
        }
    }
}
