<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;


class GraphQLServerTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use GraphQLBase;

    public function testScopeBasedAuth()
    {
        $key = $this->getNewKey(['locale.read']);
        $projectId = $this->getProject()['$id'];

        /**
         * Check that countries can be fetched
         */
        $query = $this->getQuery(self::$LIST_COUNTRIES);
        $variables = [];
        $graphQLPayload = [
            'query' => $query,
            'variables' => $variables
        ];
        $countries = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $this->assertIsArray($countries['body']['data']);
        $this->assertIsArray($countries['body']['data']['localeGetCountries']);

        $data = $countries['body']['data']['localeGetCountries'];
        $this->assertEquals(194, count($data['countries']));
        $this->assertEquals(194, $data['total']);


        /**
         * Create a key without any scopes
         */
        $key = $this->getNewKey([]);
        $countries = $this->client->call(Client::METHOD_POST, '/graphql', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
            'x-appwrite-key' => $key
        ], $graphQLPayload);

        $errorMessage = 'app.' . $projectId . '@service.localhost (role: application) missing scope (locale.read)';
        $this->assertEquals(401, $countries['headers']['status-code']);
        $this->assertEquals($countries['body']['errors'][0]['message'], $errorMessage);
        $this->assertIsArray($countries['body']['data']);
        $this->assertNull($countries['body']['data']['localeGetCountries']);
    }

}