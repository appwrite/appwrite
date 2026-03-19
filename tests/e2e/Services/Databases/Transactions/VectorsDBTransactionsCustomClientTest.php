<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Tests\E2E\Services\Databases\VectorsDB\Transactions\TransactionsBase;

class VectorsDBTransactionsCustomClientTest extends Scope
{
    use TransactionsBase;
    use ProjectCustom;
    use SideClient;
}
