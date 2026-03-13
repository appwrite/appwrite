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

class ListContinents extends Action
{
    public static function getName(): string
    {
        return 'listContinents';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale/continents')
            ->desc('List continents')
            ->groups(['api', 'locale'])
            ->label('scope', 'locale.read')
            ->label('sdk', new Method(
                namespace: $this->getSDKNamespace(),
                group: $this->getSDKGroup(),
                name: self::getName(),
                description: '/docs/references/locale/list-continents.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: SwooleResponse::STATUS_CODE_OK,
                        model: UtopiaResponse::MODEL_CONTINENT_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->inject('locale')
            ->callback($this->action(...));
    }

    public function action(UtopiaResponse $response, Locale $locale): void
    {
        $list = \array_keys(Config::getParam('locale-continents'));
        $output = [];

        foreach ($list as $value) {
            $output[] = new Document([
                'name' => $locale->getText('continents.' . \strtolower($value)),
                'code' => $value,
            ]);
        }

        \usort($output, function ($a, $b) {
            return \strcmp($a->getAttribute('name'), $b->getAttribute('name'));
        });

        $response->dynamic(new Document(['continents' => $output, 'total' => \count($output)]), UtopiaResponse::MODEL_CONTINENT_LIST);
    }
}
