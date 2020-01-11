<?php

namespace Tests\E2E;

use Tests\E2E\Client;

class ProjectLocaleTest extends BaseProjects
{
    public function testRegisterSuccess(): array
    {
        return $this->initProject([]);
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testLocaleReadSuccess(array $data): array
    {
        $locale = $this->client->call(Client::METHOD_GET, '/locale', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
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
    public function testLocaleCountriesReadSuccess(array $data): array
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
            'x-appwrite-project' => $data['projectUid'],
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
    public function testLocaleCountriesEUReadSuccess(array $data): array
    {
        $countries = $this->client->call(Client::METHOD_GET, '/locale/countries/eu', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
        ]);

        $this->assertEquals($countries['headers']['status-code'], 200);
        $this->assertIsArray($countries['body']);
        $this->assertCount(28, $countries['body']);
        $this->assertEquals($countries['body']['DE'], 'Germany');

        // Test locale code change to ES

        $countries = $this->client->call(Client::METHOD_GET, '/locale/countries/eu', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($countries['headers']['status-code'], 200);
        $this->assertIsArray($countries['body']);
        $this->assertCount(28, $countries['body']);
        $this->assertEquals($countries['body']['DE'], 'Alemania');

        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testLocaleContinentsReadSuccess(array $data): array
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
            'x-appwrite-project' => $data['projectUid'],
            'x-appwrite-locale' => 'es',
        ]);

        $this->assertEquals($continents['headers']['status-code'], 200);
        $this->assertIsArray($continents['body']);
        $this->assertCount(7, $continents['body']);
        $this->assertEquals($continents['body']['NA'], 'América del Norte');

        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testLocaleCurrenciesReadSuccess(array $data): array
    {
        $continents = $this->client->call(Client::METHOD_GET, '/locale/currencies', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $data['projectUid'],
        ]);

        $this->assertEquals($continents['headers']['status-code'], 200);
        $this->assertIsArray($continents['body']);
        $this->assertCount(117, $continents['body']);
        $this->assertEquals($continents['body'][0]['symbol'], '$');
        $this->assertEquals($continents['body'][0]['name'], 'US Dollar');

        return $data;
    }

    /**
     * @depends testRegisterSuccess
     */
    public function testLocaleLangsSuccess(array $data): array
    {
        $languages           = require('app/config/locales.php');
        $defaultCountries    = require('app/config/locales/en.countries.php');
        $defaultContinents   = require('app/config/locales/en.continents.php');

        foreach ($languages as $key => $lang) {
            $countries = $this->client->call(Client::METHOD_GET, '/locale/countries', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $data['projectUid'],
                'x-appwrite-locale' => $lang,
            ]);
            
            foreach ($countries['body'] as $i => $code) {
                $this->assertArrayHasKey($i, $defaultCountries, $i . ' country should be removed from ' . $lang);
            }

            foreach (array_keys($defaultCountries) as $i => $code) {
                $this->assertArrayHasKey($code, $countries['body'], $code . ' country is missing from ' . $lang . ' (total: ' . count($countries['body']) . ')');
            }

            $this->assertEquals($countries['headers']['status-code'], 200);
            $this->assertCount(194, $countries['body']);

            $continents = $this->client->call(Client::METHOD_GET, '/locale/continents', [
                'content-type' => 'application/json',
                'x-appwrite-project' => $data['projectUid'],
                'x-appwrite-locale' => $lang,
            ]);
            
            foreach ($continents['body'] as $i => $code) {
                $this->assertArrayHasKey($i, $defaultContinents, $i . ' continent should be removed from ' . $lang);
            }

            foreach (array_keys($defaultContinents) as $i => $code) {
                $this->assertArrayHasKey($code, $continents['body'], $code . ' continent is missing from ' . $lang . ' (total: ' . count($continents['body']) . ')');
            }

            $this->assertEquals($continents['headers']['status-code'], 200);
            $this->assertCount(7, $continents['body']);
        }

        return $data;
    }
}
