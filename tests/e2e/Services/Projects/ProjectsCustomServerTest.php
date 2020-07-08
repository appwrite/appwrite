<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Client;

class ProjectsCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testMock()
    {
        $this->assertEquals(true, true);
    }
}