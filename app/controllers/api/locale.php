<?php

global $utopia, $register, $request, $response, $projectDB, $project, $user, $audit;

use Appwrite\Database\Document;
use Utopia\App;
use Utopia\Locale\Locale;
use GeoIp2\Database\Reader;

include_once __DIR__ . '/../shared/api.php';

$utopia->get('/v1/locale')
    ->desc('Get User Locale')
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/locale/get-locale.md')
    ->action(
        function () use ($response, $request, $utopia) {
            $eu = include __DIR__.'/../../config/eu.php';
            $currencies = include __DIR__.'/../../config/currencies.php';
            $reader = new Reader(__DIR__.'/../../db/DBIP/dbip-country-lite-2020-01.mmdb');
            $output = [];
            $ip = $request->getIP();
            $time = (60 * 60 * 24 * 45); // 45 days cache
            $countries = Locale::getText('countries');
            $continents = Locale::getText('continents');

            if (App::MODE_TYPE_PRODUCTION !== $utopia->getMode()) {
                $ip = '79.177.241.94';
            }

            $output['$collection'] = 'locale';
            $output['ip'] = $ip;

            $currency = null;

            try {
                $record = $reader->country($ip);
                $output['countryCode'] = $record->country->isoCode;
                $output['country'] = (isset($countries[$record->country->isoCode])) ? $countries[$record->country->isoCode] : Locale::getText('locale.country.unknown');
                //$output['countryTimeZone'] = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $record->country->isoCode);
                $output['continent'] = (isset($continents[$record->continent->code])) ? $continents[$record->continent->code] : Locale::getText('locale.country.unknown');
                $output['continentCode'] = $record->continent->code;
                $output['eu'] = (\in_array($record->country->isoCode, $eu)) ? true : false;

                foreach ($currencies as $element) {
                    if (isset($element['locations']) && isset($element['code']) && \in_array($record->country->isoCode, $element['locations'])) {
                        $currency = $element['code'];
                    }
                }

                $output['currency'] = $currency;
            } catch (\Exception $e) {
                $output['countryCode'] = '--';
                $output['country'] = Locale::getText('locale.country.unknown');
                $output['continent'] = Locale::getText('locale.country.unknown');
                $output['continentCode'] = '--';
                $output['eu'] = false;
                $output['currency'] = $currency;
            }

            $response
                ->addHeader('Cache-Control', 'public, max-age='.$time)
                ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time).' GMT') // 45 days cache
            ;

            $response->dynamic(new Document($output));
        }
    );

$utopia->get('/v1/locale/countries')
    ->desc('List Countries')
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountries')
    ->label('sdk.description', '/docs/references/locale/get-countries.md')
    ->action(
        function () use ($response) {
            $list = Locale::getText('countries'); /* @var $list array */

            \asort($list);

            $response->json($list);
        }
    );

$utopia->get('/v1/locale/countries/eu')
    ->desc('List EU Countries')
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesEU')
    ->label('sdk.description', '/docs/references/locale/get-countries-eu.md')
    ->action(
        function () use ($response) {
            $countries = Locale::getText('countries'); /* @var $countries array */
            $eu = include __DIR__.'/../../config/eu.php';
            $list = [];

            foreach ($eu as $code) {
                if (\array_key_exists($code, $countries)) {
                    $list[$code] = $countries[$code];
                }
            }

            \asort($list);

            $response->json($list);
        }
    );

$utopia->get('/v1/locale/countries/phones')
    ->desc('List Countries Phone Codes')
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesPhones')
    ->label('sdk.description', '/docs/references/locale/get-countries-phones.md')
    ->action(
        function () use ($response) {
            $list = include __DIR__.'/../../config/phones.php'; /* @var $list array */

            $countries = Locale::getText('countries'); /* @var $countries array */

            foreach ($list as $code => $name) {
                if (\array_key_exists($code, $countries)) {
                    $list[$code] = '+'.$list[$code];
                }
            }

            \asort($list);

            $response->json($list);
        }
    );

$utopia->get('/v1/locale/continents')
    ->desc('List Continents')
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getContinents')
    ->label('sdk.description', '/docs/references/locale/get-continents.md')
    ->action(
        function () use ($response) {
            $list = Locale::getText('continents'); /* @var $list array */

            \asort($list);

            $response->json($list);
        }
    );


$utopia->get('/v1/locale/currencies')
    ->desc('List Currencies')
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCurrencies')
    ->label('sdk.description', '/docs/references/locale/get-currencies.md')
    ->action(
        function () use ($response) {
            $currencies = include __DIR__.'/../../config/currencies.php';

            $response->json($currencies);
        }
    );


$utopia->get('/v1/locale/languages')
    ->desc('List Languages')
    ->label('scope', 'locale.read')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT, APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getLanguages')
    ->label('sdk.description', '/docs/references/locale/get-languages.md')
    ->action(
        function () use ($response) {
            $languages = include __DIR__.'/../../config/languages.php';

            $response->json($languages);
        }
    );