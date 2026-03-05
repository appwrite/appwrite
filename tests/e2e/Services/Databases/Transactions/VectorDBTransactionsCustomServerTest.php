<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Services\Databases\VectorDB\Transactions\TransactionsBase;

class VectorDBTransactionsCustomServerTest extends Scope
{
    use TransactionsBase;
    use ProjectCustom;
    use SideServer;
}
