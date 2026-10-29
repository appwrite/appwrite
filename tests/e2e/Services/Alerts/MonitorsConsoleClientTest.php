<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Alerts;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class MonitorsConsoleClientTest extends Scope
{
    use MonitorsBase;
    use ProjectCustom;
    use SideConsole;

    public function testConsoleProjectIsRefused(): void
    {
        /**
         * Test for FAILURE
         */
        $list = $this->client->call(Client::METHOD_GET, '/monitors', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ]);

        // Monitors belong to a project; the console project has none to manage.
        $this->assertSame(401, $list['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $list['body']['type']);
    }
}
