<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Messaging;

use Appwrite\Tests\Async;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class MessagingConsoleClientTest extends Scope
{
    use Async;

    use MessagingBase;
    use ProjectCustom;
    use SideConsole;

}
