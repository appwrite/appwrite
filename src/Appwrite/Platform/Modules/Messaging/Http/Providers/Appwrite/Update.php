<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Appwrite;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateAppwriteProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/providers/appwrite/:providerId')
            ->desc('Update Appwrite provider')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'provider.update')
            ->label('audits.resource', 'provider/{response.$id}')
            ->label('event', 'providers.[providerId].update')
            ->label('scope', 'providers.write')
            ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'providers',
                name: 'updateAppwriteProvider',
                description: '/docs/references/messaging/update-appwrite-provider.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROVIDER,
                    )
                ]
            ))
            ->param('providerId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Provider ID.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Provider name.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
            ->param('qos', null, new Nullable(new Range(0, 1)), 'Default QoS for topics on this provider (0 or 1). Null lets the subscriber choose.', true)
            ->param('expiry', null, new Nullable(new Range(0, 604800)), 'Default message retention in seconds for offline delivery. Max 7 days (604800).', true)
            ->inject('request')
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $name, ?bool $enabled, ?int $qos, ?int $expiry, Request $request, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        if ($provider->getAttribute('provider') !== MESSAGE_PROVIDER_APPWRITE) {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
        }

        if (!\is_null($enabled)) {
            $provider->setAttribute('enabled', $enabled);
        }

        // qos and expiry are nullable defaults: an explicit null resets them (subscriber
        // chooses the QoS, no expiry). An omitted param and an explicit null both arrive as
        // null, so key on the request body's presence rather than the value.
        $params = $request->getParams();
        if (\array_key_exists('qos', $params) || \array_key_exists('expiry', $params)) {
            $options = $provider->getAttribute('options', []);
            if (\array_key_exists('qos', $params)) {
                $options['qos'] = $qos;
            }
            if (\array_key_exists('expiry', $params)) {
                $options['expiry'] = $expiry;
            }
            $provider->setAttribute('options', $options);
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $queueForEvents
            ->setParam('providerId', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    }
}
