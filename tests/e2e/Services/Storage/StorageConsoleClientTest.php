<?php

namespace Tests\E2E\Services\Storage;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\SideConsole;

class StorageConsoleClientTest extends Scope
{
    use StorageBase;
    use ProjectConsole;
    use SideConsole;
}