<?php

use Appwrite\Repository\LocaleRepository;
use Utopia\Database\Document;
use Appwrite\Utopia\Response;
use Utopia\App;

App::get('/v1/locale')
    ->desc('Get User Locale')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/locale/get-locale.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOCALE)
    ->inject('request')
    ->inject('response')
    ->inject('locale')
    ->inject('geodb')
    ->inject('localeRepository')
    ->action(function ($request, $response, $locale, $geodb, $repository) {
        /** @var Appwrite\Utopia\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Repository\LocaleRepository $repository */

        $output = $repository->get($locale, $geodb, $request->getIp());

        $time = (60 * 60 * 24 * 45); // 45 days cache

        $response
            ->addHeader('Cache-Control', 'public, max-age=' . $time)
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time) . ' GMT') // 45 days cache
        ;

        $response->dynamic(new Document($output), Response::MODEL_LOCALE);
    });

App::get('/v1/locale/countries')
    ->desc('List Countries')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountries')
    ->label('sdk.description', '/docs/references/locale/get-countries.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COUNTRY_LIST)
    ->inject('response')
    ->inject('locale')
    ->inject('localeRepository')
    ->action(function ($response, $locale, $repository) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Repository\LocaleRepository $repository */

        $output = $repository->getCountries($locale);

        $response->dynamic(new Document(['countries' => $output, 'total' => \count($output)]), Response::MODEL_COUNTRY_LIST);
    });

App::get('/v1/locale/countries/eu')
    ->desc('List EU Countries')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesEU')
    ->label('sdk.description', '/docs/references/locale/get-countries-eu.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_COUNTRY_LIST)
    ->inject('response')
    ->inject('locale')
    ->inject('localeRepository')
    ->action(function ($response, $locale, $repository) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Repository\LocaleRepository $repository */

        $output = $repository->getCountriesEU($locale);

        $response->dynamic(new Document(['countries' => $output, 'total' => \count($output)]), Response::MODEL_COUNTRY_LIST);
    });

App::get('/v1/locale/countries/phones')
    ->desc('List Countries Phone Codes')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCountriesPhones')
    ->label('sdk.description', '/docs/references/locale/get-countries-phones.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PHONE_LIST)
    ->inject('response')
    ->inject('locale')
    ->inject('localeRepository')
    ->action(function ($response, $locale, $repository) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Repository\LocaleRepository $repository */

        $output = $repository->getCountriesPhones($locale);

        $response->dynamic(new Document(['phones' => $output, 'total' => \count($output)]), Response::MODEL_PHONE_LIST);
    });

App::get('/v1/locale/continents')
    ->desc('List Continents')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getContinents')
    ->label('sdk.description', '/docs/references/locale/get-continents.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_CONTINENT_LIST)
    ->inject('response')
    ->inject('locale')
    ->inject('localeRepository')
    ->action(function ($response, $locale, $repository) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Repository\LocaleRepository $repository */

        $output = $repository->getContinents($locale);

        $response->dynamic(new Document(['continents' => $output, 'total' => \count($output)]), Response::MODEL_CONTINENT_LIST);
    });

App::get('/v1/locale/currencies')
    ->desc('List Currencies')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getCurrencies')
    ->label('sdk.description', '/docs/references/locale/get-currencies.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_CURRENCY_LIST)
    ->inject('response')
    ->inject('localeRepository')
    ->action(function ($response, $repository) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Repository\LocaleRepository $repository */

        $output = $repository->getCurrencies();

        $response->dynamic(new Document(['currencies' => $output, 'total' => \count($output)]), Response::MODEL_CURRENCY_LIST);
    });


App::get('/v1/locale/languages')
    ->desc('List Languages')
    ->groups(['api', 'locale'])
    ->label('scope', 'locale.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'locale')
    ->label('sdk.method', 'getLanguages')
    ->label('sdk.description', '/docs/references/locale/get-languages.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LANGUAGE_LIST)
    ->inject('response')
    ->inject('localeRepository')
    ->action(function ($response, $repository) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Repository\LocaleRepository $repository */

        $output = $repository->getLanguages();

        $response->dynamic(new Document(['languages' => $output, 'total' => \count($output)]), Response::MODEL_LANGUAGE_LIST);
    });