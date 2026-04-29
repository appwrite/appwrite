<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class MockPhonesCustomServerTest extends Scope
{
    use MockPhonesBase;
    use ProjectCustom;
    use SideServer;
}
