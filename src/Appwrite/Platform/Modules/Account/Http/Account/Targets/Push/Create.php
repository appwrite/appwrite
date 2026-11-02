<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Targets\Push;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createPushTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/targets/push')
            ->desc('Create push target')
            ->groups(['api', 'account'])
            ->label('scope', 'targets.write')
            ->label('audits.event', 'target.create')
            ->label('audits.resource', 'target/response.$id')
            ->label('event', 'users.[userId].targets.[targetId].create')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'pushTargets',
                name: 'createPushTarget',
                description: '/docs/references/account/create-push-target.md',
                auth: [AuthType::ADMIN, AuthType::SESSION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TARGET,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('targetId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Target ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)')
            ->param('providerId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Provider ID. Message will be sent to this target from the specified provider ID. If no provider ID is set the first setup provider will be used.', true, ['dbForProject'])
            ->param('bundleId', '', new Text(256), 'App bundle ID of the device registering an APNs token. Without `providerId`, routes the target to the enabled APNs provider for this bundle ID and `environment`.', true)
            ->param('environment', '', new WhiteList(['sandbox', 'production'], true), 'APNs environment the token belongs to, `sandbox` or `production`. Used with `bundleId`.', true)
            ->inject('queueForEvents')
            ->inject('user')
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('session');
    }
}
