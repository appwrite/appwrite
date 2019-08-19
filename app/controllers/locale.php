<?php

global $utopia, $register, $request, $response, $projectDB, $project, $user, $audit;

use Utopia\App;
use Utopia\Locale\Locale;
use GeoIp2\Database\Reader;

$utopia->get('/v1/locale')
    ->desc('Get User Locale')
    ->label('scope', 'locale.read')
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getLocale')
    ->label('sdk.description', 'Get the current user location based on IP. Returns an object with user country code, country name, continent name, continent code, ip address and suggested currency. You can use the locale header to get the data in supported language.')
    ->action(
        function() use ($response, $request, $utopia)
        {
            $eu         = include __DIR__ . '/../config/eu.php';
            $currencies = include __DIR__ . '/../config/currencies.php';
            $reader     = new Reader(__DIR__ . '/../db/GeoLite2/GeoLite2-Country.mmdb');
            $output     = [];
            $ip         = $request->getIP();
            $time       = (60 * 60 * 24 * 45); // 45 days cache
            $countries  = Locale::getText('countries');
            $continents = Locale::getText('continents');

            if(App::ENV_TYPE_PRODUCTION !== $utopia->getEnv()) {
                $ip         = '79.177.241.94';
            }

            $output['ip'] = $ip;

            $currency = null;

            try {
                $record = $reader->country($ip);
                $output['countryCode'] = $record->country->isoCode;
                $output['country'] = (isset($countries[$record->country->isoCode])) ? $countries[$record->country->isoCode] : Locale::getText('locale.country.unknown');
                //$output['countryTimeZone'] = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $record->country->isoCode);
                $output['continent'] = (isset($continents[$record->continent->code])) ? $continents[$record->continent->code] : Locale::getText('locale.country.unknown');
                $output['continentCode'] = $record->continent->code;
                $output['eu'] = (in_array($record->country->isoCode, $eu)) ? true : false;

                foreach ($currencies as $code => $element) {
                    if(isset($element['locations']) && isset($element['code']) && in_array($record->country->isoCode, $element['locations'])) {
                        $currency = $element['code'];
                    }
                }

                $output['currency'] = $currency;
            }
            catch(\Exception $e) {
                $output['countryCode'] = '--';
                $output['country'] = Locale::getText('locale.country.unknown');
                $output['continent'] = Locale::getText('locale.country.unknown');
                $output['continentCode'] = '--';
                $output['eu'] = false;
                $output['currency'] = $currency;
            }

            $response
                ->addHeader('Cache-Control', 'public, max-age=' . $time)
                ->addHeader('Expires', date('D, d M Y H:i:s', time() + $time) . ' GMT') // 45 days cache
                ->json($output);
        }
    );

$utopia->get('/v1/locale/countries')
    ->desc('List Countries')
    ->label('scope', 'locale.read')
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountries')
    ->label('sdk.description', 'List of all countries. You can use the locale header to get the data in supported language.')
    ->action(
        function() use ($response, $request)
        {
            $list = Locale::getText('countries'); /* @var $list array */

            asort($list);

            $response->json($list);
        }
    );

$utopia->get('/v1/locale/countries/eu')
    ->desc('List EU Countries')
    ->label('scope', 'locale.read')
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesEU')
    ->label('sdk.description', 'List of all countries that are currently members of the EU. You can use the locale header to get the data in supported language. UK brexit date is currently set to 2019-10-31 and will be updated if and when needed.')
    ->action(
        function() use ($response)
        {
            $countries  = Locale::getText('countries'); /* @var $countries array */
            $eu         = include __DIR__ . '/../config/eu.php';
            $list       = [];

            foreach ($eu as $code) {
                if(array_key_exists($code, $countries)) {
                    $list[$code] = $countries[$code];
                }
            }

            asort($list);

            $response->json($list);
        }
    );

$utopia->get('/v1/locale/countries/phones')
    ->desc('List Countries Phone Codes')
    ->label('scope', 'locale.read')
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesPhones')
    ->label('sdk.description', 'List of all countries phone codes. You can use the locale header to get the data in supported language.')
    ->action(
        function() use ($response)
        {
            $list       = include __DIR__ . '/../config/phones.php'; /* @var $list array */

            $countries  = Locale::getText('countries'); /* @var $countries array */

            foreach ($list as $code => $name) {
                if(array_key_exists($code, $countries)) {
                    $list[$code] = $countries[$code] . ' +' . $list[$code];
                }
            }

            asort($list);

            $response->json($list);
        }
    );

$utopia->get('/v1/locale/currencies')
    ->desc('List of currencies')
    ->label('scope', 'locale.read')
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCurrencies')
    ->label('sdk.description', 'List of all currencies, including currency symol, name, plural, and decimal digits for all major and minor currencies. You can use the locale header to get the data in supported language.')
    ->action(
        function() use ($response)
        {
            $currencies = include __DIR__ . '/../config/currencies.php';

            $response->json($currencies);
        }
    );