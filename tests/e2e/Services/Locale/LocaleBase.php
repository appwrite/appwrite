<?php

namespace Tests\E2E\Services\Locale;

use Exception;
use Tests\E2E\Client;

trait LocaleBase
{
    public function testGetLocale(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
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

    public function testGetCountries(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/countries', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertEquals(197, $response['body']['total']);
        $this->assertEquals($response['body']['countries'][0]['name'], 'Afghanistan');
        $this->assertEquals($response['body']['countries'][0]['code'], 'AF');

        // Test locale code change to ES

        $response = $this->client->call(Client::METHOD_GET, '/locale/countries', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertEquals(197, $response['body']['total']);
        $this->assertEquals($response['body']['countries'][0]['name'], 'Afganistán');
        $this->assertEquals($response['body']['countries'][0]['code'], 'AF');

        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testGetCountriesEU(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/countries/eu', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(27, $response['body']['total']);
        $this->assertIsArray($response['body']['countries']);
        $this->assertEquals($response['body']['countries'][0]['name'], 'Austria');
        $this->assertEquals($response['body']['countries'][0]['code'], 'AT');

        // Test locale code change to ES

        $response = $this->client->call(Client::METHOD_GET, '/locale/countries/eu', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(27, $response['body']['total']);
        $this->assertIsArray($response['body']['countries']);
        $this->assertEquals($response['body']['countries'][0]['name'], 'Alemania');
        $this->assertEquals($response['body']['countries'][0]['code'], 'DE');


        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testGetCountriesPhones(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/countries/phones', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertEquals(196, $response['body']['total']);
        $this->assertIsArray($response['body']['phones']);
        $this->assertEquals($response['body']['phones'][0]['code'], '+1');
        $this->assertEquals($response['body']['phones'][0]['countryName'], 'Canada');
        $this->assertEquals($response['body']['phones'][0]['countryCode'], 'CA');

        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testGetContinents(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/continents', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(7, $response['body']['total']);
        $this->assertIsArray($response['body']['continents']);
        $this->assertEquals($response['body']['continents'][0]['code'], 'AF');
        $this->assertEquals($response['body']['continents'][0]['name'], 'Africa');

        // Test locale code change to ES
        $response = $this->client->call(Client::METHOD_GET, '/locale/continents', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals(7, $response['body']['total']);
        $this->assertIsArray($response['body']['continents']);
        $this->assertEquals($response['body']['continents'][0]['code'], 'NA');
        $this->assertEquals($response['body']['continents'][0]['name'], 'América del Norte');


        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testGetCurrencies(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/currencies', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertEquals(117, $response['body']['total']);
        $this->assertEquals($response['body']['currencies'][0]['symbol'], '$');
        $this->assertEquals($response['body']['currencies'][0]['name'], 'US Dollar');

        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testGetLanguages(): array
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/locale/languages', array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertEquals(186, $response['body']['total']);

        $this->assertEquals($response['body']['languages'][0]['code'], 'aa');
        $this->assertEquals($response['body']['languages'][0]['name'], 'Afar');
        $this->assertEquals($response['body']['languages'][0]['nativeName'], 'Afar');

        $this->assertEquals($response['body']['languages'][185]['code'], 'zu');
        $this->assertEquals($response['body']['languages'][185]['name'], 'Zulu');
        $this->assertEquals($response['body']['languages'][185]['nativeName'], 'isiZulu');

        /**
         * Test for FAILURE
         */

        return [];
    }

    public function testLanguages(): array
    {
        /**
         * Test for SUCCESS
         */
        $languages           = require(__DIR__ . '/../../../../app/config/locale/codes.php');
        $defaultCountries    = array_keys(require(__DIR__ . '/../../../../app/config/locale/countries.php'));
        $defaultContinents   = array_keys(require(__DIR__ . '/../../../../app/config/locale/continents.php'));

        foreach ($languages as $lang) {
            $response = $this->client->call(Client::METHOD_GET, '/locale/countries', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-locale' => $lang['code'],
            ]);

            if (!\is_array($response['body']['countries'])) {
                throw new Exception('Failed to iterate locale: ' . $lang);
            }

            foreach ($response['body']['countries'] as $i => $code) {
                $this->assertContains($code['code'], $defaultCountries, $code['code'] . ' country should be removed from ' . $lang['code']);
            }

            $this->assertEquals($response['headers']['status-code'], 200);
            $this->assertEquals(197, $response['body']['total']);

            $response = $this->client->call(Client::METHOD_GET, '/locale/continents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $this->getProject()['$id'],
                'x-appwrite-locale' => $lang['code'],
            ]);

            foreach ($response['body']['continents'] as $i => $code) {
                $this->assertContains($code['code'], $defaultContinents, $code['code'] . ' continent should be removed from ' . $lang['code']);
            }

            $this->assertEquals($response['headers']['status-code'], 200);
            $this->assertEquals(7, $response['body']['total']);
        }

        /**
         * Test for FAILURE
         */

        return [];
    }
}
