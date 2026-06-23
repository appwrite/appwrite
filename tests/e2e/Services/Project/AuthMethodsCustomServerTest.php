<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Project;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class AuthMethodsCustomServerTest extends Scope
{
    use AuthMethodsBase;
    use ProjectCustom;
    use SideServer;
}
