<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Msg91;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
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
        return 'createMsg91Provider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/messaging/providers/msg91')
            ->desc('Create Msg91 provider')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'provider.create')
            ->label('audits.resource', 'provider/{response.$id}')
            ->label('scope', 'providers.write')
            ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
            ->label('event', 'providers.[providerId].create')
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'providers',
                name: 'createMsg91Provider',
                description: '/docs/references/messaging/create-msg91-provider.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_PROVIDER,
                    )
                ]
            ))
            ->param('providerId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getAdapter()->getMaxUIDLength()), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Provider name.')
            ->param('templateId', '', new Text(0), 'Msg91 template ID', true)
            ->param('senderId', '', new Text(0), 'Msg91 sender ID.', true)
            ->param('authKey', '', new Text(0), 'Msg91 auth key.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $name, string $templateId, string $senderId, string $authKey, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $options = [];
        $credentials = [];

        if (!empty($templateId)) {
            $credentials['templateId'] = $templateId;
        }

        if (!empty($senderId)) {
            $credentials['senderId'] = $senderId;
        }

        if (!empty($authKey)) {
            $credentials['authKey'] = $authKey;
        }

        if (
            $enabled === true
            && \array_key_exists('senderId', $credentials)
            && \array_key_exists('authKey', $credentials)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'msg91',
            'type' => MESSAGE_TYPE_SMS,
            'enabled' => $enabled,
            'credentials' => $credentials,
            'options' => $options,
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
