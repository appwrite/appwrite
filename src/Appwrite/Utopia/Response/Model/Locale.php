<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Locale extends Model
{
    public function __construct()
    {
        $this
            ->addRule('ip', [
                'type' => 'string',
                'description' => 'User IP address.',
                'example' => '127.0.0.1',
            ])
            ->addRule('countryCode', [
                'type' => 'string',
                'description' => 'Country code in [ISO 3166-1](http://en.wikipedia.org/wiki/ISO_3166-1) two-character format',
                'example' => 'US',
            ])
            ->addRule('country', [
                'type' => 'string',
                'description' => 'Country name. This field support localization.',
                'example' => 'United States',
            ])
            ->addRule('continentCode', [
                'type' => 'string',
                'description' => 'Continent code. A two character continent code "AF" for Africa, "AN" for Antarctica, "AS" for Asia, "EU" for Europe, "NA" for North America, "OC" for Oceania, and "SA" for South America.',
                'example' => 'NA',
            ])
            ->addRule('continent', [
                'type' => 'string',
                'description' => 'Continent name. This field support localization.',
                'example' => 'North America',
            ])
            ->addRule('eu', [
                'type' => 'Boolean',
                'description' => 'True if country is part of the Europian Union.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('currency', [
                'type' => 'string',
                'description' => 'ISO 4217 Email verification status.',
                'description' => 'Currency code in [ISO 4217-1](http://en.wikipedia.org/wiki/ISO_4217) three-character format',
                'example' => 'USD',
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Locale';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_LOCALE;
    }
}