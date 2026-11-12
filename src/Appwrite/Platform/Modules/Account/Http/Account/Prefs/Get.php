<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Prefs;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getPrefs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/prefs')
            ->desc('Get account preferences')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'getPrefs',
                description: '/docs/references/account/get-prefs.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PREFERENCES,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->inject('targetUser')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Document $targetUser,
    ): void {
        $prefs = $targetUser->getAttribute('prefs', []);

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    }
}
