<?php

namespace Tests\E2E\Services\Console;

use Appwrite\Extend\Exception;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class ModeTest extends Scope
{
    use ProjectConsole;
    use SideClient;

    public function testConsoleWithAdminMode(): void
    {
        $this->markTestSkipped();

        /**
         * Test for SUCCESS
         */
        /*
         $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
             'content-type' => 'application/json',
             'x-appwrite-project' => $this->getProject()['$id'],
             'x-appwrite-mode' => 'admin',
         ], $this->getHeaders()));

         $this->assertEquals(400, $response['headers']['status-code']);
         $this->assertEquals(Exception::GENERAL_BAD_REQUEST, $response['body']['type']);
         */
    }
}
