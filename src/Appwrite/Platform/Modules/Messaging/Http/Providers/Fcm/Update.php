<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Fcm;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON\FCM as FCMValidator;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateFcmProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/providers/fcm/:providerId')
            ->desc('Update FCM provider')
            ->groups(['api', 'messaging'])
            ->label('audits.event', 'provider.update')
            ->label('audits.resource', 'provider/{response.$id}')
            ->label('event', 'providers.[providerId].update')
            ->label('scope', 'providers.write')
            ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
            ->label('sdk', [
                new Method(
                    namespace: 'messaging',
                    group: 'providers',
                    name: 'updateFcmProvider',
                    description: '/docs/references/messaging/update-fcm-provider.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_PROVIDER,
                        )
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'messaging.updateFCMProvider',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'messaging',
                    group: 'providers',
                    name: 'updateFCMProvider',
                    description: '/docs/references/messaging/update-fcm-provider.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_PROVIDER,
                        )
                    ]
                )
            ])
            ->param('providerId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Provider ID.', false, ['dbForProject'])
            ->param('name', '', new Text(128), 'Provider name.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
            ->param('serviceAccountJSON', null, new Nullable(new FCMValidator()), 'FCM service account JSON.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $name, ?bool $enabled, array|string|\stdClass|null $serviceAccountJSON, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'fcm') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
        }

        if (!\is_null($serviceAccountJSON)) {
            $serviceAccountJSON = \is_string($serviceAccountJSON)
                ? \json_decode($serviceAccountJSON, true)
                : $this->normalizeJsonObject($serviceAccountJSON);

            $provider->setAttribute('credentials', [
                'serviceAccountJSON' => $serviceAccountJSON
            ]);
        }

        if (!\is_null($serviceAccountJSON) || $enabled === true) {
            $credentials = $provider->getAttribute('credentials');

            if (!\array_key_exists('serviceAccountJSON', $credentials)) {
                throw new Exception(Exception::PROVIDER_MISSING_CREDENTIALS);
            }

            $validator = new FCMValidator();

            if (!$validator->isValid($credentials['serviceAccountJSON'])) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $validator->getDescription());
            }
        }

        if (!\is_null($enabled)) {
            $provider->setAttribute('enabled', $enabled);
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $queueForEvents
            ->setParam('providerId', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    }

    /**
     * Convert a request JSON object to the associative shape credentials expect while
     * retaining empty objects at any nested depth. Returns null untouched so the
     * caller can distinguish "not provided" from an explicit empty object.
     */
    private function normalizeJsonObject(null|array|\stdClass $data): ?array
    {
        if (\is_null($data)) {
            return null;
        }

        $normalizeValue = function (mixed $value) use (&$normalizeValue): mixed {
            if ($value instanceof \stdClass) {
                $properties = (array) $value;

                return $properties === []
                    ? $value
                    : \array_map($normalizeValue, $properties);
            }

            if (\is_array($value)) {
                return \array_map($normalizeValue, $value);
            }

            return $value;
        };

        if ($data instanceof \stdClass) {
            $data = (array) $data;
        }

        return \array_map($normalizeValue, $data);
    }
}
