<?php

use Utopia\App;
use Utopia\Config\Config;

App::get('/v1/locale')
    ->desc('Get User Locale')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/locale/get-locale.md')
    ->action(function ($request, $response, $locale, $geodb) {
        /** @var Utopia\Request $request */
        /** @var Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */
        /** @var GeoIp2\Database\Reader $geodb */

        $eu = Config::getParam('locale-eu');
        $currencies = Config::getParam('locale-currencies');
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
            $record = $geodb->country($ip);
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
    }, ['request', 'response', 'locale', 'geodb']);

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
        $eu = Config::getParam('locale-eu');
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

        $list = Config::getParam('locale-phones'); /* @var $list array */

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

        $currencies = Config::getParam('locale-currencies');

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

        $languages = Config::getParam('locale-languages');

        $response->json($languages);
    }, ['response']);