<?php

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class MessagingConsoleClientTest extends Scope
{
    use MessagingBase;
    use ProjectCustom;
    use SideConsole;

}
