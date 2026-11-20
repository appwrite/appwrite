<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Bird;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateBirdProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/providers/bird/:providerId')
            ->desc('Update Bird provider')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'provider.update')
            ->label('audits.resource', 'provider/{response.$id}')
            ->label('event', 'providers.[providerId].update')
            ->label('scope', 'providers.write')
            ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'providers',
                name: 'updateBirdProvider',
                description: '/docs/references/messaging/update-bird-provider.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROVIDER,
                    )
                ]
            ))
            ->param('providerId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Provider ID.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Provider name.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
            ->param('apiKey', '', new Text(0), 'Bird workspace API key.', true)
            ->param('from', '', new Text(256), 'Sender phone number or alphanumeric sender ID. Format phone numbers with a leading \'+\' and a country code, e.g., +16175551212.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $name, ?bool $enabled, string $apiKey, string $from, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'bird') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        $updates = [];

        if (!empty($name)) {
            $updates['name'] = $name;
        }

        $options = $provider->getAttribute('options', []);
        if (!empty($from)) {
            $options['from'] = $from;
            $updates['options'] = $options;
        }

        $credentials = $provider->getAttribute('credentials', []);
        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
            $updates['credentials'] = $credentials;
        }

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    \array_key_exists('apiKey', $credentials) &&
                    \array_key_exists('from', $options)
                ) {
                    $updates['enabled'] = true;
                } else {
                    throw new Exception(Exception::PROVIDER_MISSING_CREDENTIALS);
                }
            } else {
                $updates['enabled'] = false;
            }
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), new Document($updates));

        $queueForEvents
            ->setParam('providerId', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    }
}
