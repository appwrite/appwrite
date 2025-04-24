<?php

namespace Tests\E2E\Services\Tokens;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class TokensCustomClientTest extends Scope
{
    use TokensBase;
    use ProjectCustom;
    use SideClient;

}
