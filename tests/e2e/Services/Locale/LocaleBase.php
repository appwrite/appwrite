<?php

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Client;

trait LocaleBase
{
    public function testGetLocale():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertArrayHasKey('ip', $response['body']);
        $this->assertArrayHasKey('countryCode', $response['body']);
        $this->assertArrayHasKey('country', $response['body']);
        $this->assertArrayHasKey('continent', $response['body']);
        $this->assertArrayHasKey('continentCode', $response['body']);
        $this->assertArrayHasKey('eu', $response['body']);
        $this->assertArrayHasKey('currency', $response['body']);

        /**
         * Test for FAILURE
         */
        
        return [];
    }

    public function testGetCountries():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/countries', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertCount(194, $response['body']);
        $this->assertEquals($response['body']['US'], 'United States');

        // Test locale code change to ES

        $response = $this->client->call(Client::METHOD_GET, '/locale/countries', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertCount(194, $response['body']);
        $this->assertEquals($response['body']['US'], 'Estados Unidos');
        
        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testGetCountriesEU():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/countries/eu', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertCount(27, $response['body']);
        $this->assertEquals($response['body']['DE'], 'Germany');

        // Test locale code change to ES

        $response = $this->client->call(Client::METHOD_GET, '/locale/countries/eu', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertCount(27, $response['body']);
        $this->assertEquals($response['body']['DE'], 'Alemania');
        
        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testGetCountriesPhones():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/countries/phones', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));
        
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertCount(194, $response['body']);
        $this->assertEquals($response['body']['US'], 'United States +1');
        $this->assertEquals($response['body']['IL'], 'Israel +972');
        
        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testGetContinents():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/continents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertCount(7, $response['body']);
        $this->assertEquals($response['body']['NA'], 'North America');

        // Test locale code change to ES
        $response = $this->client->call(Client::METHOD_GET, '/locale/continents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertCount(7, $response['body']);
        $this->assertEquals($response['body']['NA'], 'América del Norte');

        
        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testGetCurrencies():array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/currencies', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$uid'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertCount(117, $response['body']);
        $this->assertEquals($response['body'][0]['symbol'], '$');
        $this->assertEquals($response['body'][0]['name'], 'US Dollar');
        
        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testLangaugaes(): array
    {
        /**
         * Test for SUCCESS
         */
        $languages           = require('app/config/locales.php');
        $defaultCountries    = require('app/config/locales/en.countries.php');
        $defaultContinents   = require('app/config/locales/en.continents.php');

        foreach ($languages as $lang) {
            $response = $this->client->call(Client::METHOD_GET, '/locale/countries', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$uid'],
                'x-appwrite-locale' => $lang,
            ]);
            
            foreach ($response['body'] as $i => $code) {
                $this->assertArrayHasKey($i, $defaultCountries, $i . ' country should be removed from ' . $lang);
            }

            foreach (array_keys($defaultCountries) as $i => $code) {
                $this->assertArrayHasKey($code, $response['body'], $code . ' country is missing from ' . $lang . ' (total: ' . count($response['body']) . ')');
            }

            $this->assertEquals($response['headers']['status-code'], 200);
            $this->assertCount(194, $response['body']);

            $response = $this->client->call(Client::METHOD_GET, '/locale/continents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$uid'],
                'x-appwrite-locale' => $lang,
            ]);
            
            foreach ($response['body'] as $i => $code) {
                $this->assertArrayHasKey($i, $defaultContinents, $i . ' continent should be removed from ' . $lang);
            }

            foreach (array_keys($defaultContinents) as $i => $code) {
                $this->assertArrayHasKey($code, $response['body'], $code . ' continent is missing from ' . $lang . ' (total: ' . count($response['body']) . ')');
            }

            $this->assertEquals($response['headers']['status-code'], 200);
            $this->assertCount(7, $response['body']);
        }
                
        /**
         * Test for FAILURE
         */

        return [];
    }
}