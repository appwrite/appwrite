<?php

namespace Tests\E2E\Services\Account;

use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class AccountCustomClientTest extends Scope
{
    use AccountBase;
    use ProjectCustom;
    use SideClient;

    public function testCreateOAuth2AccountSession():array
    {
        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/'.$this->getProject()['$uid'].'/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/'.$provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);

        return [];
    }
}