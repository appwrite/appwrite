<?php

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Locale\Locale;

class LocaleEndpoints {
    private const CACHE_TIME = 3888000; // 45 days in seconds
    private const DEFAULT_LABELS = [
        'scope' => 'locale.read',
        'sdk.auth' => [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT],
        'sdk.namespace' => 'locale',
        'sdk.response.type' => Response::CONTENT_TYPE_JSON,
    ];

    public static function register(): void 
    {
        self::registerGetLocale();
        self::registerListEndpoints();
    }

    private static function registerGetLocale(): void 
    {
        App::get('/v1/locale')
            ->desc('Get user locale')
            ->groups(['api', 'locale'])
            ->labels(array_merge(self::DEFAULT_LABELS, [
                'sdk.method' => 'get',
                'sdk.description' => '/docs/references/locale/get-locale.md',
                'sdk.response.code' => Response::STATUS_CODE_OK,
                'sdk.response.model' => Response::MODEL_LOCALE,
                'sdk.offline.model' => '/localed',
                'sdk.offline.key' => 'current',
            ]))
            ->inject('request')
            ->inject('response')
            ->inject('locale')
            ->inject('geodb')
            ->action(function (Request $request, Response $response, Locale $locale, Reader $geodb) {
                $output = self::getUserLocaleInfo($request->getIP(), $locale, $geodb);
                
                $response
                    ->addHeader('Cache-Control', 'public, max-age=' . self::CACHE_TIME)
                    ->addHeader('Expires', gmdate('D, d M Y H:i:s', time() + self::CACHE_TIME) . ' GMT')
                    ->dynamic(new Document($output), Response::MODEL_LOCALE);
            });
    }

    private static function getUserLocaleInfo(string $ip, Locale $locale, Reader $geodb): array 
    {
        $eu = Config::getParam('locale-eu');
        $currencies = Config::getParam('locale-currencies');
        $record = $geodb->get($ip);

        if (!$record) {
            return [
                'ip' => $ip,
                'countryCode' => '--',
                'country' => $locale->getText('locale.country.unknown'),
                'continent' => $locale->getText('locale.country.unknown'),
                'continentCode' => '--',
                'eu' => false,
                'currency' => null
            ];
        }

        $countryCode = $record['country']['iso_code'];
        $continentCode = $record['continent']['code'];
        
        return [
            'ip' => $ip,
            'countryCode' => $countryCode,
            'country' => $locale->getText('countries.' . strtolower($countryCode), $locale->getText('locale.country.unknown')),
            'continent' => $locale->getText('continents.' . strtolower($continentCode), $locale->getText('locale.country.unknown')),
            'continentCode' => $continentCode,
            'eu' => in_array($countryCode, $eu, true),
            'currency' => self::getCountryCurrency($countryCode, $currencies)
        ];
    }

    private static function getCountryCurrency(string $countryCode, array $currencies): ?string 
    {
        foreach ($currencies as $element) {
            if (!empty($element['locations']) && !empty($element['code']) && 
                in_array($countryCode, $element['locations'], true)) {
                return $element['code'];
            }
        }
        return null;
    }

    private static function registerListEndpoints(): void 
    {
        self::registerListCodes();
        self::registerListCountries();
        self::registerListEUCountries();
        self::registerListPhones();
        self::registerListContinents();
        self::registerListCurrencies();
        self::registerListLanguages();
    }

    private static function registerListCodes(): void 
    {
        App::get('/v1/locale/codes')
            ->desc('List locale codes')
            ->groups(['api', 'locale'])
            ->labels(array_merge(self::DEFAULT_LABELS, [
                'sdk.method' => 'listCodes',
                'sdk.description' => '/docs/references/locale/list-locale-codes.md',
                'sdk.response.model' => Response::MODEL_LOCALE_CODE_LIST,
                'sdk.offline.model' => '/locale/localeCode',
                'sdk.offline.key' => 'current',
            ]))
            ->inject('response')
            ->action(function (Response $response) {
                $codes = Config::getParam('locale-codes');
                $response->dynamic(new Document([
                    'localeCodes' => $codes,
                    'total' => count($codes),
                ]), Response::MODEL_LOCALE_CODE_LIST);
            });
    }

    private static function registerListCountries(): void 
    {
        App::get('/v1/locale/countries')
            ->desc('List countries')
            ->groups(['api', 'locale'])
            ->labels(array_merge(self::DEFAULT_LABELS, [
                'sdk.method' => 'listCountries',
                'sdk.description' => '/docs/references/locale/list-countries.md',
                'sdk.response.model' => Response::MODEL_COUNTRY_LIST,
                'sdk.offline.model' => '/locale/countries',
                'sdk.offline.response.key' => 'code',
            ]))
            ->inject('response')
            ->inject('locale')
            ->action(function (Response $response, Locale $locale) {
                $countries = self::getFormattedCountryList(
                    Config::getParam('locale-countries'),
                    $locale
                );
                
                $response->dynamic(new Document([
                    'countries' => $countries,
                    'total' => count($countries)
                ]), Response::MODEL_COUNTRY_LIST);
            });
    }

    private static function getFormattedCountryList(array $codes, Locale $locale): array 
    {
        $output = array_map(function($code) use ($locale) {
            return new Document([
                'name' => $locale->getText('countries.' . strtolower($code)),
                'code' => $code,
            ]);
        }, array_filter($codes, fn($code) => 
            $locale->getText('countries.' . strtolower($code), false) !== false
        ));

        usort($output, fn($a, $b) => 
            strcmp($a->getAttribute('name'), $b->getAttribute('name'))
        );

        return $output;
    }

    private static function registerListEUCountries(): void 
    {
        App::get('/v1/locale/countries/eu')
            ->desc('List EU countries')
            ->groups(['api', 'locale'])
            ->labels(array_merge(self::DEFAULT_LABELS, [
                'sdk.method' => 'listCountriesEU',
                'sdk.description' => '/docs/references/locale/list-countries-eu.md',
                'sdk.response.model' => Response::MODEL_COUNTRY_LIST,
                'sdk.offline.model' => '/locale/countries/eu',
                'sdk.offline.response.key' => 'code',
            ]))
            ->inject('response')
            ->inject('locale')
            ->action(function (Response $response, Locale $locale) {
                $countries = self::getFormattedCountryList(
                    Config::getParam('locale-eu'),
                    $locale
                );
                
                $response->dynamic(new Document([
                    'countries' => $countries,
                    'total' => count($countries)
                ]), Response::MODEL_COUNTRY_LIST);
            });
    }

    private static function registerListPhones(): void 
    {
        App::get('/v1/locale/countries/phones')
            ->desc('List countries phone codes')
            ->groups(['api', 'locale'])
            ->labels(array_merge(self::DEFAULT_LABELS, [
                'sdk.method' => 'listCountriesPhones',
                'sdk.description' => '/docs/references/locale/list-countries-phones.md',
                'sdk.response.model' => Response::MODEL_PHONE_LIST,
                'sdk.offline.model' => '/locale/countries/phones',
                'sdk.offline.response.key' => 'countryCode',
            ]))
            ->inject('response')
            ->inject('locale')
            ->action(function (Response $response, Locale $locale) {
                $phones = self::getFormattedPhoneList(
                    Config::getParam('locale-phones'),
                    $locale
                );
                
                $response->dynamic(new Document([
                    'phones' => $phones,
                    'total' => count($phones)
                ]), Response::MODEL_PHONE_LIST);
            });
    }

    private static function getFormattedPhoneList(array $phones, Locale $locale): array 
    {
        asort($phones);
        
        return array_values(array_filter(
            array_map(function($code, $phone) use ($locale) {
                $countryName = $locale->getText('countries.' . strtolower($code), false);
                if ($countryName === false) {
                    return null;
                }
                
                return new Document([
                    'code' => '+' . $phone,
                    'countryCode' => $code,
                    'countryName' => $countryName,
                ]);
            }, array_keys($phones), $phones)
        ));
    }

    private static function registerListContinents(): void 
    {
        App::get('/v1/locale/continents')
            ->desc('List continents')
            ->groups(['api', 'locale'])
            ->labels(array_merge(self::DEFAULT_LABELS, [
                'sdk.method' => 'listContinents',
                'sdk.description' => '/docs/references/locale/list-continents.md',
                'sdk.response.model' => Response::MODEL_CONTINENT_LIST,
                'sdk.offline.model' => '/locale/continents',
                'sdk.offline.response.key' => 'code',
            ]))
            ->inject('response')
            ->inject('locale')
            ->action(function (Response $response, Locale $locale) {
                $continents = array_map(function($code) use ($locale) {
                    return new Document([
                        'name' => $locale->getText('continents.' . strtolower($code)),
                        'code' => $code,
                    ]);
                }, Config::getParam('locale-continents'));

                usort($continents, fn($a, $b) => 
                    strcmp($a->getAttribute('name'), $b->getAttribute('name'))
                );

                $response->dynamic(new Document([
                    'continents' => $continents,
                    'total' => count($continents)
                ]), Response::MODEL_CONTINENT_LIST);
            });
    }

    private static function registerSimpleListEndpoint(
        string $path,
        string $desc,
        string $method,
        string $docPath,
        string $responseModel,
        string $offlineModel,
        string $configParam,
        string $responseKey
    ): void {
        App::get($path)
            ->desc($desc)
            ->groups(['api', 'locale'])
            ->labels(array_merge(self::DEFAULT_LABELS, [
                'sdk.method' => $method,
                'sdk.description' => $docPath,
                'sdk.response.model' => $responseModel,
                'sdk.offline.model' => $offlineModel,
                'sdk.offline.response.key' => $responseKey,
            ]))
            ->inject('response')
            ->action(function (Response $response) use ($configParam, $responseKey) {
                $list = array_map(
                    fn($node) => new Document($node), 
                    Config::getParam($configParam)
                );
                
                $response->dynamic(new Document([
                    $responseKey => $list,
                    'total' => count($list)
                ]), $responseModel);
            });
    }

    private static function registerListCurrencies(): void 
    {
        self::registerSimpleListEndpoint(
            '/v1/locale/currencies',
            'List currencies',
            'listCurrencies',
            '/docs/references/locale/list-currencies.md',
            Response::MODEL_CURRENCY_LIST,
            '/locale/currencies',
            'locale-currencies',
            'currencies'
        );
    }

    private static function registerListLanguages(): void 
    {
        self::registerSimpleListEndpoint(
            '/v1/locale/languages',
            'List languages',
            'listLanguages',
            '/docs/references/locale/list-languages.md',
            Response::MODEL_LANGUAGE_LIST,
            '/locale/languages',
            'locale-languages',
            'languages'
        );
    }
}

// Register all endpoints
LocaleEndpoints::register();