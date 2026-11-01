<?php

namespace Appwrite\Platform\Modules\Locale\Http\Locale\Countries\Phones;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listCountriesPhones';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale/countries/phones')
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
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(bool $includeTotal, Response $response, Locale $locale): void
    {
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

        $response->dynamic(new Document(['phones' => $output, 'total' => $includeTotal ? \count($output) : 0]), Response::MODEL_PHONE_LIST);
    }
}
