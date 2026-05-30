<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class LabelsConsoleClientTest extends Scope
{
    use LabelsBase;
    use ProjectCustom;
    use SideConsole;
}
