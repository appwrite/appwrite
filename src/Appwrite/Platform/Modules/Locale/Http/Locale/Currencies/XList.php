<?php

namespace Appwrite\Platform\Modules\Locale\Http\Locale\Currencies;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listCurrencies';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale/currencies')
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
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(bool $includeTotal, Response $response): void
    {
        $list = Config::getParam('locale-currencies');

        $list = array_map(fn ($node) => new Document($node), $list);

        $response->dynamic(new Document(['currencies' => $list, 'total' => $includeTotal ? \count($list) : 0]), Response::MODEL_CURRENCY_LIST);
    }
}
