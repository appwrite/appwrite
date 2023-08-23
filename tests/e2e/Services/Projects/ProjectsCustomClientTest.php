<?php

namespace Tests\E2E\Services\Projects;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Client;

class ProjectsCustomClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;

    public function testMock()
    {
        $this->assertEquals(true, true);
    }
}
