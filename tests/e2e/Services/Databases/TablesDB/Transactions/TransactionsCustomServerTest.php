<?php

namespace Tests\E2E\Services\Databases\TablesDB\Transactions;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class TransactionsCustomServerTest extends Scope
{
    use TransactionsBase;
    use ProjectCustom;
    use SideServer;
}
