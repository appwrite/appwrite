<?php

namespace Appwrite\Platform\Modules\Locale\Services;

use Appwrite\Platform\Modules\Locale\Http\Locale\Get;
use Appwrite\Platform\Modules\Locale\Http\Locale\ListCodes;
use Appwrite\Platform\Modules\Locale\Http\Locale\ListCountries;
use Appwrite\Platform\Modules\Locale\Http\Locale\ListCountriesEU;
use Appwrite\Platform\Modules\Locale\Http\Locale\ListCountriesPhones;
use Appwrite\Platform\Modules\Locale\Http\Locale\ListContinents;
use Appwrite\Platform\Modules\Locale\Http\Locale\ListCurrencies;
use Appwrite\Platform\Modules\Locale\Http\Locale\ListLanguages;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;
        $this
            ->addAction(Get::getName(), new Get())
            ->addAction(ListCodes::getName(), new ListCodes())
            ->addAction(ListCountries::getName(), new ListCountries())
            ->addAction(ListCountriesEU::getName(), new ListCountriesEU())
            ->addAction(ListCountriesPhones::getName(), new ListCountriesPhones())
            ->addAction(ListContinents::getName(), new ListContinents())
            ->addAction(ListCurrencies::getName(), new ListCurrencies())
            ->addAction(ListLanguages::getName(), new ListLanguages());
    }
}
