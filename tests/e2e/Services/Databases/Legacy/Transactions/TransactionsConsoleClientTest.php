<?php

namespace Tests\E2E\Services\Databases\Legacy\Transactions;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

class TransactionsConsoleClientTest extends Scope
{
    use TransactionsBase;
    use ProjectCustom;
    use SideConsole;
}
