<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Notifications;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class NotificationsCustomServerTest extends Scope
{
    use NotificationsBase;
    use ProjectCustom;
    use SideServer;
}
