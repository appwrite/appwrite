<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Scopes\ApiDocumentsDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class DocumentsDBCustomClientTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideClient;
    use ApiDocumentsDB;
}
