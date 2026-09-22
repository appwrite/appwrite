<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Sendgrid;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
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

    public static function getName()
    {
        return 'updateSendgridProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/providers/sendgrid/:providerId')
            ->desc('Update Sendgrid provider')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'provider.update')
            ->label('audits.resource', 'provider/{response.$id}')
            ->label('event', 'providers.[providerId].update')
            ->label('scope', 'providers.write')
            ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'providers',
                name: 'updateSendgridProvider',
                description: '/docs/references/messaging/update-sendgrid-provider.md',
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
            ->param('apiKey', '', new Text(0), 'Sendgrid API key.', true)
            ->param('fromName', '', new Text(128), 'Sender Name.', true)
            ->param('fromEmail', '', new Email(), 'Sender email address.', true)
            ->param('replyToName', '', new Text(128), 'Name set in the Reply To field for the mail. Default value is Sender Name.', true)
            ->param('replyToEmail', '', new Text(128), 'Email set in the Reply To field for the mail. Default value is Sender Email.', true)
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
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'sendgrid') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
        }

        $options = $provider->getAttribute('options');

        if (!empty($fromName)) {
            $options['fromName'] = $fromName;
        }

        if (!empty($fromEmail)) {
            $options['fromEmail'] = $fromEmail;
        }

        if (!empty($replyToName)) {
            $options['replyToName'] = $replyToName;
        }

        if (!empty($replyToEmail)) {
            $options['replyToEmail'] = $replyToEmail;
        }

        $provider->setAttribute('options', $options);

        if (!empty($apiKey)) {
            $provider->setAttribute('credentials', [
                'apiKey' => $apiKey,
            ]);
        }

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    \array_key_exists('apiKey', $provider->getAttribute('credentials')) &&
                    \array_key_exists('fromEmail', $provider->getAttribute('options'))
                ) {
                    $provider->setAttribute('enabled', true);
                } else {
                    throw new Exception(Exception::PROVIDER_MISSING_CREDENTIALS);
                }
            } else {
                $provider->setAttribute('enabled', false);
            }
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $queueForEvents
            ->setParam('providerId', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    }
}
