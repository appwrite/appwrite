<?php

namespace Appwrite\Platform\Modules\Locale\Http\Locale;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use Utopia\Swoole\Response as SwooleResponse;

class ListCountriesEU extends Action
{
    public static function getName(): string
    {
        return 'listCountriesEU';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale/countries/eu')
            ->desc('List EU countries')
            ->groups(['api', 'locale'])
            ->label('scope', 'locale.read')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/locale/list-countries-eu.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_COUNTRY_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(UtopiaResponse $response, Locale $locale): void
    {
        $eu = Config::getParam('locale-eu');
        $output = [];

        foreach ($eu as $code) {
            if ($locale->getText('countries.' . \strtolower($code), false) !== false) {
                $output[] = new Document([
                    'name' => $locale->getText('countries.' . \strtolower($code)),
                    'code' => $code,
                ]);
            }
        }

        \usort($output, function ($a, $b) {
            return \strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        $response->dynamic(new Document(['countries' => $output, 'total' => \count($output)]), UtopiaResponse::MODEL_COUNTRY_LIST);
    }
}
