<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\VectorsDB\Transactions;

use Tests\E2E\Scopes\ApiVectorsDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class TransactionsConsoleClientTest extends Scope
{
    use TransactionsBase;
    use ProjectCustom;
    use SideConsole;
    use ApiVectorsDB;
}
