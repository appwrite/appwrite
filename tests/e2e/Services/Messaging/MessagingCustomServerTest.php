<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Messaging;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class MessagingCustomServerTest extends Scope
{
    use MessagingBase;
    use ProjectCustom;
    use SideServer;
}
