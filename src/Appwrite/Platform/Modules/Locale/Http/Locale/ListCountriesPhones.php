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

class ListCountriesPhones extends Action
{
    public static function getName(): string
    {
        return 'listCountriesPhones';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale/countries/phones')
            ->desc('List countries phone codes')
            ->groups(['api', 'locale'])
            ->label('scope', 'locale.read')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/locale/list-countries-phones.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_PHONE_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(UtopiaResponse $response, Locale $locale): void
    {
        $list = Config::getParam('locale-phones'); /* @var $list array */
        $output = [];

        \asort($list);

        foreach ($list as $code => $name) {
            if ($locale->getText('countries.' . \strtolower($code), false) !== false) {
                $output[] = new Document([
                    'code' => '+' . $list[$code],
                    'countryCode' => $code,
                    'countryName' => $locale->getText('countries.' . \strtolower($code)),
                ]);
            }
        }

        $response->dynamic(new Document(['phones' => $output, 'total' => \count($output)]), UtopiaResponse::MODEL_PHONE_LIST);
    }
}
