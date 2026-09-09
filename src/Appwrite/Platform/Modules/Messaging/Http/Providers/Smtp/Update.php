<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Smtp;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Emails\Validator\Email;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateSmtpProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/messaging/providers/smtp/:providerId')
            ->desc('Update SMTP provider')
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
                    name: 'updateSmtpProvider',
                    description: '/docs/references/messaging/update-smtp-provider.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_PROVIDER,
                        )
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'messaging.updateSMTPProvider',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'messaging',
                    group: 'providers',
                    name: 'updateSMTPProvider',
                    description: '/docs/references/messaging/update-smtp-provider.md',
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
            ->param('host', '', new Text(0), 'SMTP hosts. Either a single hostname or multiple semicolon-delimited hostnames. You can also specify a different port for each host such as `smtp1.example.com:25;smtp2.example.com`. You can also specify encryption type, for example: `tls://smtp1.example.com:587;ssl://smtp2.example.com:465"`. Hosts will be tried in order.', true)
            ->param('port', null, new Nullable(new Range(1, 65535)), 'SMTP port.', true)
            ->param('username', '', new Text(0), 'Authentication username.', true)
            ->param('password', '', new PasswordFormat(new Text(0)), 'Authentication password.', true)
            ->param('encryption', '', new WhiteList(['none', 'ssl', 'tls']), 'Encryption type. Can be \'ssl\' or \'tls\'', true, enum: new Enum(name: 'SmtpEncryption'))
            ->param('autoTLS', null, new Nullable(new Boolean()), 'Enable SMTP AutoTLS feature.', true)
            ->param('mailer', '', new Text(0), 'The value to use for the X-Mailer header.', true)
            ->param('fromName', '', new Text(128), 'Sender Name.', true)
            ->param('fromEmail', '', new Email(), 'Sender email address.', true)
            ->param('replyToName', '', new Text(128), 'Name set in the Reply To field for the mail. Default value is Sender Name.', true)
            ->param('replyToEmail', '', new Text(128), 'Email set in the Reply To field for the mail. Default value is Sender Email.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $name, string $host, ?int $port, string $username, string $password, string $encryption, ?bool $autoTLS, string $mailer, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        if ($provider->getAttribute('provider') !== 'smtp') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
        }

        $options = $provider->getAttribute('options');

        if (!empty($encryption)) {
            $options['encryption'] = $encryption === 'none' ? '' : $encryption;
        }

        if (!\is_null($autoTLS)) {
            $options['autoTLS'] = $autoTLS;
        }

        if (!empty($mailer)) {
            $options['mailer'] = $mailer;
        }

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

        $credentials = $provider->getAttribute('credentials');

        if (!empty($host)) {
            $credentials['host'] = $host;
        }

        if (!\is_null($port)) {
            $credentials['port'] = $port;
        }

        if (!empty($username)) {
            $credentials['username'] = $username;
        }

        if (!empty($password)) {
            $credentials['password'] = $password;
        }

        $provider->setAttribute('credentials', $credentials);

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    !empty($options['fromEmail'])
                    && \array_key_exists('host', $credentials)
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
