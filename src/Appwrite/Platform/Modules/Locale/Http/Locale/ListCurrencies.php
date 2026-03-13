<?php

namespace Appwrite\Platform\Modules\Locale\Http\Locale;

use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Swoole\Response as SwooleResponse;

class ListCurrencies extends Action
{
    public static function getName(): string
    {
        return 'listCurrencies';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale/currencies')
            ->desc('List currencies')
            ->groups(['api', 'locale'])
            ->label('scope', 'locale.read')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/locale/list-currencies.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_CURRENCY_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(UtopiaResponse $response): void
    {
        $list = Config::getParam('locale-currencies');

        $list = \array_map(fn ($node) => new Document($node), $list);

        $response->dynamic(new Document(['currencies' => $list, 'total' => \count($list)]), UtopiaResponse::MODEL_CURRENCY_LIST);
    }
}
