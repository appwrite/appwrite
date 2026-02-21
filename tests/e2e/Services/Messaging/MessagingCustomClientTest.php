<?php

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class MessagingCustomClientTest extends Scope
{
    use MessagingBase;
    use ProjectCustom;
    use SideClient;
}
