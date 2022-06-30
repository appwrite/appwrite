<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Scopes\SideServer;

class GraphQLDatabaseClientTest extends Scope
{
    use ProjectCustom;
    use SideClient;
    use GraphQLBase;
}