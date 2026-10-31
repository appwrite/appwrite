<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\Phone;

use Appwrite\Event\Event;
use Appwrite\Event\Realtime;
use Appwrite\Geo\Geo;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Auth\Store;
use Utopia\Bus\Bus;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePhoneSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/sessions/phone')
            ->desc('Update phone session')
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->groups(['api', 'account', 'session'])
            ->label('scope', 'sessions.write')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'updatePhoneSession',
                description: '/docs/references/account/create-session.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SESSION,
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.6.0',
                    replaceWith: 'account.createSession'
                ),
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', ['ip:{ip},userId:{param-userId}', 'projectId:{projectId},url:{url},userId:{param-userId}'])
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('secret', '', new Text(256), 'Valid verification token.')
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('platform')
            ->inject('locale')
            ->inject('geo')
            ->inject('queueForEvents')
            ->inject('bus')
            ->inject('store')
            ->inject('proofForToken')
            ->inject('proofForCode')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('authorization')
            ->inject('mqtt')
            ->inject('queueForRealtime')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        string $secret,
        Request $request,
        Response $response,
        User $user,
        Database $dbForProject,
        Document $project,
        array $platform,
        Locale $locale,
        Geo $geo,
        Event $queueForEvents,
        Bus $bus,
        Store $store,
        ProofsToken $proofForToken,
        ProofsCode $proofForCode,
        bool $domainVerification,
        ?string $cookieDomain,
        Authorization $authorization,
        Mqtt $mqtt,
        Realtime $queueForRealtime,
    ): void {
        $this->createSession($userId, $secret, $request, $response, $user, $dbForProject, $project, $platform, $locale, $geo, $queueForEvents, $bus, $store, $proofForToken, $proofForCode, $domainVerification, $cookieDomain, $authorization, $mqtt, $queueForRealtime);
    }
}
