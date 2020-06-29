<?php

use Utopia\App;
use GeoIp2\Database\Reader;

App::get('/v1/locale')
    ->desc('Get User Locale')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/locale/get-locale.md')
    ->action(function ($request, $response, $locale) {
        /** @var Utopia\Request $request */
        /** @var Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */

        $eu = include __DIR__.'/../../config/eu.php';
        $currencies = include __DIR__.'/../../config/currencies.php';
        $reader = new Reader(__DIR__.'/../../db/DBIP/dbip-country-lite-2020-01.mmdb');
        $output = [];
        $ip = $request->getIP();
        $time = (60 * 60 * 24 * 45); // 45 days cache
        $countries = $locale->getText('countries');
        $continents = $locale->getText('continents');

        if (!App::isProduction()) {
            $ip = '79.177.241.94';
        }

        $output['ip'] = $ip;

        $currency = null;

        try {
            $record = $reader->country($ip);
            $output['countryCode'] = $record->country->isoCode;
            $output['country'] = (isset($countries[$record->country->isoCode])) ? $countries[$record->country->isoCode] : $locale->getText('locale.country.unknown');
            //$output['countryTimeZone'] = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $record->country->isoCode);
            $output['continent'] = (isset($continents[$record->continent->code])) ? $continents[$record->continent->code] : $locale->getText('locale.country.unknown');
            $output['continentCode'] = $record->continent->code;
            $output['eu'] = (\in_array($record->country->isoCode, $eu)) ? true : false;

            foreach ($currencies as $code => $element) {
                if (isset($element['locations']) && isset($element['code']) && \in_array($record->country->isoCode, $element['locations'])) {
                    $currency = $element['code'];
                }
            }

            $output['currency'] = $currency;
        } catch (\Exception $e) {
            $output['countryCode'] = '--';
            $output['country'] = $locale->getText('locale.country.unknown');
            $output['continent'] = $locale->getText('locale.country.unknown');
            $output['continentCode'] = '--';
            $output['eu'] = false;
            $output['currency'] = $currency;
        }

        $response
            ->addHeader('Cache-Control', 'public, max-age='.$time)
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time).' GMT') // 45 days cache
            ->json($output);
    }, ['request', 'response', 'locale']);

App::get('/v1/locale/countries')
    ->desc('List Countries')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountries')
    ->label('sdk.description', '/docs/references/locale/get-countries.md')
    ->action(function ($response, $locale) {
        /** @var Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */

        $list = $locale->getText('countries'); /* @var $list array */

        \asort($list);

        $response->json($list);
    }, ['response', 'locale']);

App::get('/v1/locale/countries/eu')
    ->desc('List EU Countries')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesEU')
    ->label('sdk.description', '/docs/references/locale/get-countries-eu.md')
    ->action(function ($response, $locale) {
        /** @var Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */

        $countries = $locale->getText('countries'); /* @var $countries array */
        $eu = include __DIR__.'/../../config/eu.php';
        $list = [];

        foreach ($eu as $code) {
            if (\array_key_exists($code, $countries)) {
                $list[$code] = $countries[$code];
            }
        }

        \asort($list);

        $response->json($list);
    }, ['response', 'locale']);

App::get('/v1/locale/countries/phones')
    ->desc('List Countries Phone Codes')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesPhones')
    ->label('sdk.description', '/docs/references/locale/get-countries-phones.md')
    ->action(function ($response, $locale) {
        /** @var Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */

        $list = include __DIR__.'/../../config/phones.php'; /* @var $list array */

        $countries = $locale->getText('countries'); /* @var $countries array */

        foreach ($list as $code => $name) {
            if (\array_key_exists($code, $countries)) {
                $list[$code] = '+'.$list[$code];
            }
        }

        \asort($list);

        $response->json($list);
    }, ['response', 'locale']);

App::get('/v1/locale/continents')
    ->desc('List Continents')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getContinents')
    ->label('sdk.description', '/docs/references/locale/get-continents.md')
    ->action(function ($response, $locale) {
        /** @var Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */

        $list = $locale->getText('continents'); /* @var $list array */

        \asort($list);

        $response->json($list);
    }, ['response', 'locale']);


App::get('/v1/locale/currencies')
    ->desc('List Currencies')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCurrencies')
    ->label('sdk.description', '/docs/references/locale/get-currencies.md')
    ->action(function ($response) {
        /** @var Utopia\Response $response */

        $currencies = include __DIR__.'/../../config/currencies.php';

        $response->json($currencies);
    }, ['response']);


App::get('/v1/locale/languages')
    ->desc('List Languages')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getLanguages')
    ->label('sdk.description', '/docs/references/locale/get-languages.md')
    ->action(function ($response) {
        /** @var Utopia\Response $response */

        $languages = include __DIR__.'/../../config/languages.php';

        $response->json($languages);
    }, ['response']);