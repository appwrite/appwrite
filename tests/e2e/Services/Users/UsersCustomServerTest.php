<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Users;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class UsersCustomServerTest extends Scope
{
    use UsersBase;
    use ProjectCustom;
    use SideServer;
}
