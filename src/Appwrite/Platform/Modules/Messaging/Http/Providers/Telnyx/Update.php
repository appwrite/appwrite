<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Telnyx;

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

    public static function getName()
    {
        return 'updateTelnyxProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/providers/telnyx/:providerId')
            ->desc('Update Telnyx provider')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'provider.update')
            ->label('audits.resource', 'provider/{response.$id}')
            ->label('event', 'providers.[providerId].update')
            ->label('scope', 'providers.write')
            ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'providers',
                name: 'updateTelnyxProvider',
                description: <<<EOT
                Update a Telnyx provider by its unique ID.
                EOT,
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
            ->param('apiKey', '', new Text(0), 'Telnyx API key.', true)
            ->param('from', '', new Text(256), 'Sender number.', true)
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

        if ($provider->getAttribute('provider') !== 'telnyx') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        $updates = new Document();

        if (!empty($name)) {
            $updates->setAttribute('name', $name);
        }

        $options = $provider->getAttribute('options', []);

        if (!empty($from)) {
            $options['from'] = $from;
            $updates->setAttribute('options', $options);
        }

        $credentials = $provider->getAttribute('credentials', []);

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
            $updates->setAttribute('credentials', $credentials);
        }

        if (!\is_null($enabled)) {
            if ($enabled && (!\array_key_exists('apiKey', $credentials) || !\array_key_exists('from', $options))) {
                throw new Exception(Exception::PROVIDER_MISSING_CREDENTIALS);
            }

            $updates->setAttribute('enabled', $enabled);
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $updates);

        $queueForEvents
            ->setParam('providerId', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    }
}
