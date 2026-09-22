<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class MessagingCustomClientTest extends Scope
{
    use MessagingBase;
    use ProjectCustom;
    use SideClient;
}
