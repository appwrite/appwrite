<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Tests\E2E\Services\Databases\VectorDB\Transactions\TransactionsBase;

class VectorDBTransactionsConsoleClientTest extends Scope
{
    use TransactionsBase;
    use ProjectCustom;
    use SideConsole;
}
