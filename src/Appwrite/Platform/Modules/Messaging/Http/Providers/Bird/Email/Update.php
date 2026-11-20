<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Bird\Email;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Emails\Validator\Email;
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
        return 'updateBirdEmailProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/providers/bird-email/:providerId')
            ->desc('Update Bird email provider')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'provider.update')
            ->label('audits.resource', 'provider/{response.$id}')
            ->label('event', 'providers.[providerId].update')
            ->label('scope', 'providers.write')
            ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'providers',
                name: 'updateBirdEmailProvider',
                description: <<<EOT
                Update a Bird email provider by its unique ID.
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
            ->param('apiKey', '', new Text(0), 'Bird workspace API key.', true)
            ->param('fromName', '', new Text(128), 'Sender Name.', true)
            ->param('fromEmail', '', new Email(), 'Sender email address. Its domain must be verified in the Bird workspace.', true)
            ->param('replyToName', '', new Text(128), 'Name set in the Reply To field for the mail. Default value is Sender Name.', true)
            ->param('replyToEmail', '', new Email(), 'Email set in the Reply To field for the mail. Default value is Sender Email.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $name, ?bool $enabled, string $apiKey, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        // Bird has an SMS provider under the same name, which the Bird provider endpoints update.
        if ($provider->getAttribute('provider') !== 'bird' || $provider->getAttribute('type') !== MESSAGE_TYPE_EMAIL) {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        $updates = [];

        if (!empty($name)) {
            $updates['name'] = $name;
        }

        $options = $provider->getAttribute('options', []);
        $changed = \array_filter([
            'fromName' => $fromName,
            'fromEmail' => $fromEmail,
            'replyToName' => $replyToName,
            'replyToEmail' => $replyToEmail,
        ]);
        if (!empty($changed)) {
            $options = \array_merge($options, $changed);
            $updates['options'] = $options;
        }

        $credentials = $provider->getAttribute('credentials', []);
        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
            $updates['credentials'] = $credentials;
        }

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (!empty($credentials['apiKey']) && !empty($options['fromEmail'])) {
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
