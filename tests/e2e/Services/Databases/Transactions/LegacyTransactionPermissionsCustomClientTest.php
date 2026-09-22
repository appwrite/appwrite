<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

final class LegacyTransactionPermissionsCustomClientTest extends Scope
{
    use TransactionPermissionsBase;
    use ProjectCustom;
    use SideClient;
    use ApiLegacy;
}
