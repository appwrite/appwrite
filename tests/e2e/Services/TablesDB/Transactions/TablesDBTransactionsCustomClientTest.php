<?php

namespace Tests\E2E\Services\TablesDB\Transactions;

use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Databases\Transactions\TransactionsBase;
use Tests\E2E\Traits\DatabasesUrlHelpers;

class TablesDBTransactionsCustomClientTest extends Scope
{
    use TransactionsBase;
    use DatabasesUrlHelpers;
    use ProjectCustom;
    use SideClient;
    use ApiTablesDB;
}
