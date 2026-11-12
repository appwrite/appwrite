<?php

namespace Appwrite\Platform\Modules\Locale\Services;

use Appwrite\Platform\Modules\Locale\Http\Locale\Codes\XList as ListCodes;
use Appwrite\Platform\Modules\Locale\Http\Locale\Continents\XList as ListContinents;
use Appwrite\Platform\Modules\Locale\Http\Locale\Countries\EU\XList as ListCountriesEU;
use Appwrite\Platform\Modules\Locale\Http\Locale\Countries\Phones\XList as ListCountriesPhones;
use Appwrite\Platform\Modules\Locale\Http\Locale\Countries\XList as ListCountries;
use Appwrite\Platform\Modules\Locale\Http\Locale\Currencies\XList as ListCurrencies;
use Appwrite\Platform\Modules\Locale\Http\Locale\Get as GetLocale;
use Appwrite\Platform\Modules\Locale\Http\Locale\Languages\XList as ListLanguages;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction('get', GetLocale::class);
        $this->addAction('listCodes', ListCodes::class);
        $this->addAction('listCountries', ListCountries::class);
        $this->addAction('listCountriesEU', ListCountriesEU::class);
        $this->addAction('listCountriesPhones', ListCountriesPhones::class);
        $this->addAction('listContinents', ListContinents::class);
        $this->addAction('listCurrencies', ListCurrencies::class);
        $this->addAction('listLanguages', ListLanguages::class);
    }
}
