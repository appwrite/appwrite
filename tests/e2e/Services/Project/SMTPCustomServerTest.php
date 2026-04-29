<?php

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class SMTPCustomServerTest extends Scope
{
    use SMTPBase;
    use ProjectCustom;
    use SideServer;
}
