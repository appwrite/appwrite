<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Alerts;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;

final class RulesConsoleClientTest extends Scope
{
    use RulesBase;
    use ProjectCustom;
    use SideConsole;

    public function testConsoleProjectIsRefused(): void
    {
        /**
         * Test for FAILURE
         */
        $list = $this->client->call(Client::METHOD_GET, '/alerts/rules', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-project' => 'console',
        ]);

        // Alert rules belong to a project; the console project has none to manage.
        $this->assertSame(401, $list['headers']['status-code']);
        $this->assertSame('general_unauthorized_scope', $list['body']['type']);
    }
}
