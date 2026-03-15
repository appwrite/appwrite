<?php

namespace Tests\E2E\Services\TablesDB\Transactions;

use Tests\E2E\Scopes\ApiTablesDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Databases\Transactions\TransactionPermissionsBase;

class TablesDBTransactionPermissionsCustomClientTest extends Scope
{
    use TransactionPermissionsBase;
    use ProjectCustom;
    use SideClient;
    use ApiTablesDB;
}
