<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Notifications;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class NotificationsCustomConsoleTest extends Scope
{
    use NotificationsBase;
    use ProjectCustom;
    use SideConsole;
}
