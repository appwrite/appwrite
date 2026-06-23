<?php

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Locale\Locale;

App::get('/v1/locale')
    ->desc('Get user locale')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk', new Method(
        namespace: 'locale',
        group: null,
        name: 'get',
        description: '/docs/references/locale/get-locale.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOCALE,
            )
        ]
    ))
    ->inject('request')
    ->inject('response')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (Request $request, Response $response, Locale $locale, Reader $geodb) {
        $eu = Config::getParam('locale-eu');
        $currencies = Config::getParam('locale-currencies');
        $output = [];
        $ip = $request->getIP();

        $output['ip'] = $ip;

        $currency = null;

        $record = $geodb->get($ip);

        if ($record) {
            $output['countryCode'] = $record['country']['iso_code'];
            $output['country'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            $output['continent'] = $locale->getText('continents.' . strtolower($record['continent']['code']), $locale->getText('locale.country.unknown'));
            $output['continentCode'] = $record['continent']['code'];
            $output['eu'] = (\in_array($record['country']['iso_code'], $eu)) ? true : false;

            foreach ($currencies as $code => $element) {
                if (isset($element['locations']) && isset($element['code']) && \in_array($record['country']['iso_code'], $element['locations'])) {
                    $currency = $element['code'];
                }
            }

            $output['currency'] = $currency;
        } else {
            $output['countryCode'] = '--';
            $output['country'] = $locale->getText('locale.country.unknown');
            $output['continent'] = $locale->getText('locale.country.unknown');
            $output['continentCode'] = '--';
            $output['eu'] = false;
            $output['currency'] = $currency;
        }

        $response->dynamic(new Document($output), Response::MODEL_LOCALE);
    });

App::get('/v1/locale/codes')
    ->desc('List locale codes')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk', new Method(
        namespace: 'locale',
        group: null,
        name: 'listCodes',
        description: '/docs/references/locale/list-locale-codes.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOCALE_CODE_LIST,
            )
        ]
    ))
    ->inject('response')
    ->action(function (Response $response) {
        $codes = Config::getParam('locale-codes');
        $response->dynamic(new Document([
            'localeCodes' => $codes,
            'total' => count($codes),
        ]), Response::MODEL_LOCALE_CODE_LIST);
    });

App::get('/v1/locale/countries')
    ->desc('List countries')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk', new Method(
        namespace: 'locale',
        group: null,
        name: 'listCountries',
        description: '/docs/references/locale/list-countries.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_COUNTRY_LIST,
            )
        ]
    ))
    ->inject('response')
    ->inject('locale')
    ->action(function (Response $response, Locale $locale) {
        $list = array_keys(Config::getParam('locale-countries')); /* @var $list array */
        $output = [];

        foreach ($list as $value) {
            $output[] = new Document([
                'name' => $locale->getText('countries.' . strtolower($value)),
                'code' => $value,
            ]);
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        $response->dynamic(new Document(['countries' => $output, 'total' => \count($output)]), Response::MODEL_COUNTRY_LIST);
    });

App::get('/v1/locale/countries/eu')
    ->desc('List EU countries')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk', new Method(
        namespace: 'locale',
        group: null,
        name: 'listCountriesEU',
        description: '/docs/references/locale/list-countries-eu.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_COUNTRY_LIST,
            )
        ]
    ))
    ->inject('response')
    ->inject('locale')
    ->action(function (Response $response, Locale $locale) {
        $eu = Config::getParam('locale-eu');
        $output = [];

        foreach ($eu as $code) {
            if ($locale->getText('countries.' . strtolower($code), false) !== false) {
                $output[] = new Document([
                    'name' => $locale->getText('countries.' . strtolower($code)),
                    'code' => $code,
                ]);
            }
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        $response->dynamic(new Document(['countries' => $output, 'total' => \count($output)]), Response::MODEL_COUNTRY_LIST);
    });

App::get('/v1/locale/countries/phones')
    ->desc('List countries phone codes')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk', new Method(
        namespace: 'locale',
        group: null,
        name: 'listCountriesPhones',
        description: '/docs/references/locale/list-countries-phones.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PHONE_LIST,
            )
        ]
    ))
    ->inject('response')
    ->inject('locale')
    ->action(function (Response $response, Locale $locale) {
        $list = Config::getParam('locale-phones'); /* @var $list array */
        $output = [];

        \asort($list);

        foreach ($list as $code => $name) {
            if ($locale->getText('countries.' . strtolower($code), false) !== false) {
                $output[] = new Document([
                    'code' => '+' . $list[$code],
                    'countryCode' => $code,
                    'countryName' => $locale->getText('countries.' . strtolower($code)),
                ]);
            }
        }

        $response->dynamic(new Document(['phones' => $output, 'total' => \count($output)]), Response::MODEL_PHONE_LIST);
    });

App::get('/v1/locale/continents')
    ->desc('List continents')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk', new Method(
        namespace: 'locale',
        group: null,
        name: 'listContinents',
        description: '/docs/references/locale/list-continents.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_CONTINENT_LIST,
            )
        ]
    ))
    ->inject('response')
    ->inject('locale')
    ->action(function (Response $response, Locale $locale) {
        $list = array_keys(Config::getParam('locale-continents'));

        foreach ($list as $value) {
            $output[] = new Document([
                'name' => $locale->getText('continents.' . strtolower($value)),
                'code' => $value,
            ]);
        }

        usort($output, function ($a, $b) {
            return strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        $response->dynamic(new Document(['continents' => $output, 'total' => \count($output)]), Response::MODEL_CONTINENT_LIST);
    });

App::get('/v1/locale/currencies')
    ->desc('List currencies')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk', new Method(
        namespace: 'locale',
        group: null,
        name: 'listCurrencies',
        description: '/docs/references/locale/list-currencies.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_CURRENCY_LIST,
            )
        ]
    ))
    ->inject('response')
    ->action(function (Response $response) {
        $list = Config::getParam('locale-currencies');

        $list = array_map(fn ($node) => new Document($node), $list);

        $response->dynamic(new Document(['currencies' => $list, 'total' => \count($list)]), Response::MODEL_CURRENCY_LIST);
    });


App::get('/v1/locale/languages')
    ->desc('List languages')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk', new Method(
        namespace: 'locale',
        group: null,
        name: 'listLanguages',
        description: '/docs/references/locale/list-languages.md',
        auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LANGUAGE_LIST,
            )
        ]
    ))
    ->inject('response')
    ->action(function (Response $response) {
        $list = Config::getParam('locale-languages');

        $list = array_map(fn ($node) => new Document($node), $list);

        $response->dynamic(new Document(['languages' => $list, 'total' => \count($list)]), Response::MODEL_LANGUAGE_LIST);
    });
