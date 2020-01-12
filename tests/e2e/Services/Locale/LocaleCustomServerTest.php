<?php

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class LocaleCustomServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;

    public function testCreateLocale():array
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/avatars/credit-cards/visa', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
            'X-appwrite-key' => $this->getProject()['apiKey'],
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);
        
        return [];
    }
}