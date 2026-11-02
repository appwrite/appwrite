<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateEmailVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/verifications/email')
            ->httpAlias('/v1/account/verification')
            ->desc('Update email verification (confirmation)')
            ->groups(['api', 'account'])
            ->label('scope', 'public')
            ->label('event', 'users.[userId].verification.[tokenId].update')
            ->label('audits.event', 'verification.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'verification',
                    name: 'updateEmailVerification',
                    description: '/docs/references/account/update-email-verification.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_TOKEN,
                        )
                    ],
                    contentType: ContentType::JSON
                ),
                new Method(
                    namespace: 'account',
                    group: 'verification',
                    name: 'updateVerification',
                    description: '/docs/references/account/update-email-verification.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_TOKEN,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.updateEmailVerification'
                    ),
                    public: false,
                )
            ])
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},userId:{param-userId}')
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'User ID.', false, ['dbForProject'])
            ->param('secret', '', new Text(256), 'Valid verification token.')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('proofForToken')
            ->inject('authorization');
    }
}
