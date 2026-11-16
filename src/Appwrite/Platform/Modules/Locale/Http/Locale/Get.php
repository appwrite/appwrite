<?php

namespace Appwrite\Platform\Modules\Locale\Http\Locale;

use Appwrite\Geo\Geo;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'get';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale')
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
            ->inject('geo')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response, Locale $locale, Geo $geo): void
    {
        $geoRecord = $geo->get($request->getIP());
        $response->dynamic(new Document([
            'ip' => $geoRecord->getIp(),
            'countryCode' => $geoRecord->getCountryCode(),
            'country' => $geoRecord->getCountryName(),
            'continentCode' => $geoRecord->getContinentCode(),
            'continent' => $geoRecord->getContinent(),
            'eu' => $geoRecord->isEu(),
            'currency' => $geoRecord->getCurrency() ?? '',
        ]), Response::MODEL_LOCALE);
    }
}
