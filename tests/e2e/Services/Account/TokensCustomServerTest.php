<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Account;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class TokensCustomServerTest extends Scope
{
    use TokensBase;
    use ProjectCustom;
    use SideServer;
}
