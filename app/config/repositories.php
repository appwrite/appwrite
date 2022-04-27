<?php

use Appwrite\Repository\LocaleRepository;
use Appwrite\Utopia\Response;

return [
    Response::MODEL_LOCALE => LocaleRepository::class,
    Response::MODEL_COUNTRY => LocaleRepository::class,
    Response::MODEL_COUNTRY_LIST => LocaleRepository::class,
    Response::MODEL_CONTINENT => LocaleRepository::class,
    Response::MODEL_CONTINENT_LIST => LocaleRepository::class,
    Response::MODEL_CURRENCY => LocaleRepository::class,
    Response::MODEL_CURRENCY_LIST => LocaleRepository::class,
    Response::MODEL_LANGUAGE => LocaleRepository::class,
    Response::MODEL_LANGUAGE_LIST => LocaleRepository::class,
    Response::MODEL_PHONE => LocaleRepository::class,
    Response::MODEL_PHONE_LIST => LocaleRepository::class,
];
