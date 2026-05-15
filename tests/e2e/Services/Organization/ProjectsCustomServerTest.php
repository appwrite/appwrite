<?php

namespace Tests\E2E\Services\Organization;

use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServerOrganization;

class ProjectsCustomServerTest extends Scope
{
    use ProjectsBase;
    use ProjectConsole;
    use SideServerOrganization;
}
