<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Twilio;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
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
        return 'updateTwilioProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/providers/twilio/:providerId')
            ->desc('Update Twilio provider')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'provider.update')
            ->label('audits.resource', 'provider/{response.$id}')
            ->label('event', 'providers.[providerId].update')
            ->label('scope', 'providers.write')
            ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
            ->label('sdk', new Method(
                namespace: 'messaging',
                group: 'providers',
                name: 'updateTwilioProvider',
                description: '/docs/references/messaging/update-twilio-provider.md',
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
            ->param('accountSid', '', new Text(0), 'Twilio account secret ID.', true)
            ->param('authToken', '', new Text(0), 'Twilio authentication token.', true)
            ->param('from', '', new Text(256), 'Sender phone number or alphanumeric sender ID. Format phone numbers with a leading \'+\' and a country code, e.g., +16175551212.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $name, ?bool $enabled, string $accountSid, string $authToken, string $from, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'twilio') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
        }

        if (!empty($from)) {
            $provider->setAttribute('options', [
                'from' => $from,
            ]);
        }

        $credentials = $provider->getAttribute('credentials');

        if (!empty($accountSid)) {
            $credentials['accountSid'] = $accountSid;
        }

        if (!empty($authToken)) {
            $credentials['authToken'] = $authToken;
        }

        $provider->setAttribute('credentials', $credentials);

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    \array_key_exists('accountSid', $credentials) &&
                    \array_key_exists('authToken', $credentials) &&
                    \array_key_exists('from', $provider->getAttribute('options'))
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
