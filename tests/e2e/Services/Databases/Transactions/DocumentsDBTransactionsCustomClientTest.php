<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Scopes\ApiDocumentsDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Traits\DatabasesUrlHelpers;

class DocumentsDBTransactionsCustomClientTest extends Scope
{
    use TransactionsBase;
    use DatabasesUrlHelpers;
    use ProjectCustom;
    use SideClient;
    use ApiDocumentsDB;
}
