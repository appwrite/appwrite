<?php

namespace Tests\E2E\Services\GraphQL;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class GraphQLLocalizationTest extends Scope
{
    use ProjectCustom;
    use SideServer;
    use GraphQLBase;

    public function testGetLocale(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = \urlencode($this->getQuery(self::$GET_LOCALE));

        $locale = $this->client->call(Client::METHOD_GET, '/graphql?query=' . $query, \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertIsArray($locale['body']['data']);
        $this->assertArrayNotHasKey('errors', $locale['body']);
        $locale = $locale['body']['data']['localeGet'];
        $this->assertIsArray($locale);

        return $locale;
    }

    public function testGetCountries(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$LIST_COUNTRIES);
        $graphQLPayload = [
            'query' => $query,
        ];

        $countries = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($countries['body']['data']);
        $this->assertArrayNotHasKey('errors', $countries['body']);
        $countries = $countries['body']['data']['localeListCountries'];
        $this->assertIsArray($countries);
        $this->assertGreaterThan(0, \count($countries));

        return $countries;
    }

    public function testGetCountriesEU(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$LIST_EU_COUNTRIES);
        $graphQLPayload = [
            'query' => $query,
        ];

        $countries = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($countries['body']['data']);
        $this->assertArrayNotHasKey('errors', $countries['body']);
        $countries = $countries['body']['data']['localeListCountriesEU'];
        $this->assertIsArray($countries);
        $this->assertGreaterThan(0, \count($countries));

        return $countries;
    }

    public function testGetCountriesPhones(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$LIST_COUNTRY_PHONE_CODES);
        $graphQLPayload = [
            'query' => $query,
        ];

        $countries = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($countries['body']['data']);
        $this->assertArrayNotHasKey('errors', $countries['body']);
        $countries = $countries['body']['data']['localeListCountriesPhones'];
        $this->assertIsArray($countries);
        $this->assertGreaterThan(0, \count($countries));

        return $countries;
    }

    public function testGetContinents(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$LIST_CONTINENTS);
        $graphQLPayload = [
            'query' => $query,
        ];

        $continents = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($continents['body']['data']);
        $this->assertArrayNotHasKey('errors', $continents['body']);
        $continents = $continents['body']['data']['localeListContinents'];
        $this->assertIsArray($continents);
        $this->assertGreaterThan(0, \count($continents));

        return $continents;
    }

    public function testGetCurrencies(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$LIST_CURRENCIES);
        $graphQLPayload = [
            'query' => $query,
        ];

        $currencies = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($currencies['body']['data']);
        $this->assertArrayNotHasKey('errors', $currencies['body']);
        $currencies = $currencies['body']['data']['localeListCurrencies'];
        $this->assertIsArray($currencies);
        $this->assertGreaterThan(0, \count($currencies));

        return $currencies;
    }

    public function testGetLanguages(): array
    {
        $projectId = $this->getProject()['$id'];
        $query = $this->getQuery(self::$LIST_LANGUAGES);
        $graphQLPayload = [
            'query' => $query,
        ];

        $languages = $this->client->call(Client::METHOD_POST, '/graphql', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), $graphQLPayload);

        $this->assertIsArray($languages['body']['data']);
        $this->assertArrayNotHasKey('errors', $languages['body']);
        $languages = $languages['body']['data']['localeListLanguages'];
        $this->assertIsArray($languages);
        $this->assertGreaterThan(0, \count($languages));

        return $languages;
    }
}
