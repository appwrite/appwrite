<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Apns;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createApnsProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/messaging/providers/apns')
            ->desc('Create APNS provider')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'provider.create')
            ->label('audits.resource', 'provider/{response.$id}')
            ->label('event', 'providers.[providerId].create')
            ->label('scope', 'providers.write')
            ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
            ->label('sdk', [
                new Method(
                    namespace: 'messaging',
                    group: 'providers',
                    name: 'createApnsProvider',
                    description: '/docs/references/messaging/create-apns-provider.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_PROVIDER,
                        )
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'messaging.createAPNSProvider',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'messaging',
                    group: 'providers',
                    name: 'createAPNSProvider',
                    description: '/docs/references/messaging/create-apns-provider.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_PROVIDER,
                        )
                    ]
                )
            ])
            ->param('providerId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Provider name.')
            ->param('authKey', '', new Text(0), 'APNS authentication key.', true)
            ->param('authKeyId', '', new Text(0), 'APNS authentication key ID.', true)
            ->param('teamId', '', new Text(0), 'APNS team ID.', true)
            ->param('bundleId', '', new Text(0), 'APNS bundle ID.', true)
            ->param('sandbox', false, new Boolean(), 'Use APNS sandbox environment.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $name, string $authKey, string $authKeyId, string $teamId, string $bundleId, bool $sandbox, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $credentials = [];

        if (!empty($authKey)) {
            $credentials['authKey'] = $authKey;
        }

        if (!empty($authKeyId)) {
            $credentials['authKeyId'] = $authKeyId;
        }

        if (!empty($teamId)) {
            $credentials['teamId'] = $teamId;
        }

        if (!empty($bundleId)) {
            $credentials['bundleId'] = $bundleId;
        }

        if (
            $enabled === true
            && \array_key_exists('authKey', $credentials)
            && \array_key_exists('authKeyId', $credentials)
            && \array_key_exists('teamId', $credentials)
            && \array_key_exists('bundleId', $credentials)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $options = [
            'sandbox' => $sandbox
        ];

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'apns',
            'type' => MESSAGE_TYPE_PUSH,
            'enabled' => $enabled,
            'credentials' => $credentials,
            'options' => $options
        ]);

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('providerId', $provider->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    }
}
