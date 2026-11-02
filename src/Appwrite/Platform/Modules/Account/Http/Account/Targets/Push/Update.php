<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Targets\Push;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePushTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/targets/:targetId/push')
            ->desc('Update push target')
            ->groups(['api', 'account'])
            ->label('scope', 'targets.write')
            ->label('audits.event', 'target.update')
            ->label('audits.resource', 'target/response.$id')
            ->label('event', 'users.[userId].targets.[targetId].update')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'pushTargets',
                name: 'updatePushTarget',
                description: '/docs/references/account/update-push-target.md',
                auth: [AuthType::ADMIN, AuthType::SESSION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TARGET,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Target ID.', false, ['dbForProject'])
            ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)')
            ->param('bundleId', '', new Text(256), 'App bundle ID of the device registering an APNs token. Routes an APNs target to the enabled APNs provider for this bundle ID and `environment`.', true)
            ->param('environment', '', new WhiteList(['sandbox', 'production'], true), 'APNs environment the token belongs to, `sandbox` or `production`. Used with `bundleId`.', true)
            ->inject('queueForEvents')
            ->inject('user')
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization');
    }
}
