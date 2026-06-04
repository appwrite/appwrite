<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Avatars;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class AvatarsCustomClientTest extends Scope
{
    use AvatarsBase;
    use ProjectCustom;
    use SideClient;
}
