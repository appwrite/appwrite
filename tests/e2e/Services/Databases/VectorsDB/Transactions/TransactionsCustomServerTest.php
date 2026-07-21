<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\VectorsDB\Transactions;

use Tests\E2E\Scopes\ApiVectorsDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class TransactionsCustomServerTest extends Scope
{
    use TransactionsBase;
    use ProjectCustom;
    use SideServer;
    use ApiVectorsDB;
}
