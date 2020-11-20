<?php

namespace Tests\E2E\Services\Webhooks;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class WebhooksCustomClientTest extends Scope
{
    use WebhooksBase;
    use ProjectCustom;
    use SideClient;
}