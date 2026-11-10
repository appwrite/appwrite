<?php

namespace Appwrite\Platform\Modules\Locale\Http\Locale\Codes;

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
        return 'listCodes';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/locale/codes')
            ->desc('List locale codes')
            ->groups(['api', 'locale'])
            ->label('scope', 'locale.read')
            ->label('sdk', new Method(
                namespace: 'locale',
                group: null,
                name: 'listCodes',
                description: '/docs/references/locale/list-locale-codes.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_LOCALE_CODE_LIST,
                    )
                ]
            ))
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(bool $includeTotal, Response $response): void
    {
        $codes = Config::getParam('locale-codes');
        $response->dynamic(new Document([
            'localeCodes' => $codes,
            'total' => $includeTotal ? count($codes) : 0,
        ]), Response::MODEL_LOCALE_CODE_LIST);
    }
}
