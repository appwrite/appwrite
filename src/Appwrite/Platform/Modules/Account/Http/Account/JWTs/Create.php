<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\JWTs;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createJWT';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/jwts')
            ->httpAlias('/v1/account/jwt')
            ->desc('Create JWT')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'account')
            ->label('auth.type', 'jwt')
            ->label('impersonation', 'allow')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'tokens',
                name: 'createJWT',
                description: '/docs/references/account/create-jwt.md',
                auth: [AuthType::ADMIN, AuthType::SESSION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_JWT,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->param('duration', 900, new Range(0, 3600), 'Time in seconds before JWT expires. Default duration is 900 seconds, and maximum is 3600 seconds.', true)
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('abuse-key', 'url:{url},userId:{userId}')
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('store')
            ->inject('proofForToken')
            ->inject('project')
            ->inject('console')
            ->inject('mode');
    }
}
