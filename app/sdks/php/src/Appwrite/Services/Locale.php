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
     * Get the current user location based on IP. Returns an object with user
     * country code, country name, continent name, continent code, ip address and
     * suggested currency. You can use the locale header to get the data in
     * supported language.
     *
     * @throws Exception
     * @return array
     */
    public function get()
    {
        $path   = str_replace([], [], '/locale');
        $params = [];


        return $this->client->call(Client::METHOD_GET, $path, [
        ], $params);
    }

    /**
     * List Countries
     *
     * List of all countries. You can use the locale header to get the data in
     * supported language.
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
     * List of all countries that are currently members of the EU. You can use the
     * locale header to get the data in supported language.
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
     * List of all countries phone codes. You can use the locale header to get the
     * data in supported language.
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

}