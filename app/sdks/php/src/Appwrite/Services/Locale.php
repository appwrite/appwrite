<?php

namespace Appwrite\Services;

use Exception;
use Appwrite\Client;
use Appwrite\Service;

class Locale extends Service
{
    /**
     * Get User Locale
     *
     * /docs/references/locale/get-locale.md
     *
     * @throws Exception
     * @return array
     */
    public function getLocale()
    {
        $path   = str_replace([], [], '/locale');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * List Countries
     *
     * /docs/references/locale/get-countires.md
     *
     * @throws Exception
     * @return array
     */
    public function getCountries()
    {
        $path   = str_replace([], [], '/locale/countries');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * List EU Countries
     *
     * /docs/references/locale/get-countries-eu.md
     *
     * @throws Exception
     * @return array
     */
    public function getCountriesEU()
    {
        $path   = str_replace([], [], '/locale/countries/eu');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * List Countries Phone Codes
     *
     * /docs/references/locale/get-countries-phones.md
     *
     * @throws Exception
     * @return array
     */
    public function getCountriesPhones()
    {
        $path   = str_replace([], [], '/locale/countries/phones');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * List of currencies
     *
     * /docs/references/locale/get-currencies.md
     *
     * @throws Exception
     * @return array
     */
    public function getCurrencies()
    {
        $path   = str_replace([], [], '/locale/currencies');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

}