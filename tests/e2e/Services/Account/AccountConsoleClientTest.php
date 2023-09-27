<?php

namespace Tests\E2E\Services\Account;

use Appwrite\Extend\Exception;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\Helpers\ID;
use Tests\E2E\Client;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

class AccountConsoleClientTest extends Scope
{
    use AccountBase;
    use ProjectConsole;
    use SideClient;
}
