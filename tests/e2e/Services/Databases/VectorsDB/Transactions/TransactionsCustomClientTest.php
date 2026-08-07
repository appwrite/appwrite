<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\VectorsDB\Transactions;

use Tests\E2E\Scopes\ApiVectorsDB;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class TransactionsCustomClientTest extends Scope
{
    use TransactionsBase;
    use ProjectCustom;
    use SideClient;
    use ApiVectorsDB;
}
