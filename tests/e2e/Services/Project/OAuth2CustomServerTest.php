<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class OAuth2CustomServerTest extends Scope
{
    use OAuth2Base;
    use ProjectCustom;
    use SideServer;
}
