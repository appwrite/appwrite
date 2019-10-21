<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ProjectLocaleTest extends BaseProjects
{
    public function testRegisterSuccess()
    {
        return $this->initProject([]);
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testLocaleReadSuccess($data)
    {
        $locale = $this->client->call(Client::METHOD_GET, '/locale', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            //'x-appwrite-key' => $data['projectAPIKeySecret'],
        ]);

        $this->assertArrayHasKey('ip', $locale['body']);
        $this->assertArrayHasKey('countryCode', $locale['body']);
        $this->assertArrayHasKey('country', $locale['body']);
        $this->assertArrayHasKey('continent', $locale['body']);
        $this->assertArrayHasKey('continentCode', $locale['body']);
        $this->assertArrayHasKey('eu', $locale['body']);
        $this->assertArrayHasKey('currency', $locale['body']);
        
        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testLocaleCountriesReadSuccess($data)
    {
        $countries = $this->client->call(Client::METHOD_GET, '/locale/countries', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
        ]);

        $this->assertEquals($countries['headers']['status-code'], 200);
        $this->assertIsArray($countries['body']);
        $this->assertCount(194, $countries['body']);
        $this->assertEquals($countries['body']['US'], 'United States');
       
        // Test locale code change to ES

        $countries = $this->client->call(Client::METHOD_GET, '/locale/countries', [
            'content-type' => 'application/json',
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($countries['headers']['status-code'], 200);
        $this->assertIsArray($countries['body']);
        $this->assertCount(194, $countries['body']);
        $this->assertEquals($countries['body']['US'], 'Estados Unidos');

        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testLocaleContinentReadSuccess($data)
    {
        $continents = $this->client->call(Client::METHOD_GET, '/locale/continents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
        ]);

        $this->assertEquals($continents['headers']['status-code'], 200);
        $this->assertIsArray($continents['body']);
        $this->assertCount(7, $continents['body']);
        $this->assertEquals($continents['body']['NA'], 'North America');
       
        // Test locale code change to ES
        $continents = $this->client->call(Client::METHOD_GET, '/locale/continents', [
            'content-type' => 'application/json',
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($continents['headers']['status-code'], 200);
        $this->assertIsArray($continents['body']);
        $this->assertCount(7, $continents['body']);
        $this->assertEquals($continents['body']['NA'], 'América del Norte');

        return $data;
    }
}
