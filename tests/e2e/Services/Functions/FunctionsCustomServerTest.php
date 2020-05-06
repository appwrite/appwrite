<?php

namespace Tests\E2E\Services\Functions;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class FunctionsConsoleServerTest extends Scope
{
    use FunctionsBase;
    use ProjectCustom;
    use SideServer;

    public function testCreate():array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_POST, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test',
            'vars' => [
                'key1' => 'value1',
                'key2' => 'value2',
                'key3' => 'value3',
            ],
            'trigger' => 'event',
            'events' => [
                'account.create',
                'account.delete',
            ],
            'schedule' => '* * * * *',
            'timeout' => 10,
        ]);

        $functionId = (isset($response1['body']['$id'])) ? $response1['body']['$id'] : '';

        $this->assertEquals(201, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);
        $this->assertEquals('Test', $response1['body']['name']);
        $this->assertIsInt($response1['body']['dateCreated']);
        $this->assertIsInt($response1['body']['dateUpdated']);
        $this->assertEquals('', $response1['body']['tag']);
        // $this->assertEquals([
        //     'key1' => 'value1',
        //     'key2' => 'value2',
        //     'key3' => 'value3',
        // ], $response1['body']['vars']);
        $this->assertEquals('event', $response1['body']['trigger']);
        $this->assertEquals([
            'account.create',
            'account.delete',
        ], $response1['body']['events']);
        $this->assertEquals('* * * * *', $response1['body']['schedule']);
        $this->assertEquals(10, $response1['body']['timeout']);
       
        /**
         * Test for FAILURE
         */

        return [
            'functionId' => $functionId,
        ];
    }

    /**
     * @depends testCreate
     */
    public function testList(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['sum'], 1);
        $this->assertIsArray($function['body']['functions']);
        $this->assertCount(1, $function['body']['functions']);
        $this->assertEquals($function['body']['functions'][0]['name'], 'Test');

        return $data;
    }

    /**
     * @depends testList
     */
    public function testGet(array $data):array
    {
        /**
         * Test for SUCCESS
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/' . $data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 200);
        $this->assertEquals($function['body']['name'], 'Test');
               
        /**
         * Test for FAILURE
         */
        $function = $this->client->call(Client::METHOD_GET, '/functions/x', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($function['headers']['status-code'], 404);

        return $data;
    }

    /**
     * @depends testGet
     */
    public function testUpdate($data):array
    {
        /**
         * Test for SUCCESS
         */
        $response1 = $this->client->call(Client::METHOD_PUT, '/functions/'.$data['functionId'], array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'name' => 'Test1',
            'vars' => [
                'key4' => 'value4',
                'key5' => 'value5',
                'key6' => 'value6',
            ],
            'trigger' => 'scheudle',
            'events' => [
                'account.update.name',
                'account.update.email',
            ],
            'schedule' => '* * * * 1',
            'timeout' => 5,
        ]);

        $this->assertEquals(200, $response1['headers']['status-code']);
        $this->assertNotEmpty($response1['body']['$id']);
        $this->assertEquals('Test1', $response1['body']['name']);
        $this->assertIsInt($response1['body']['dateCreated']);
        $this->assertIsInt($response1['body']['dateUpdated']);
        $this->assertEquals('', $response1['body']['tag']);
        // $this->assertEquals([
        //     'key4' => 'value4',
        //     'key5' => 'value5',
        //     'key6' => 'value6',
        // ], $response1['body']['vars']);
        $this->assertEquals('scheudle', $response1['body']['trigger']);
        $this->assertEquals([
            'account.update.name',
            'account.update.email',
        ], $response1['body']['events']);
        $this->assertEquals('* * * * 1', $response1['body']['schedule']);
        $this->assertEquals(5, $response1['body']['timeout']);
       
        /**
         * Test for FAILURE
         */

        return $data;
    }
}