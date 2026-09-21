<?php

namespace Appwrite\Platform\Modules\Messaging\Http\Providers\Smtp;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Emails\Validator\Email;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createSmtpProvider';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/messaging/providers/smtp')
            ->desc('Create SMTP provider')
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
                    name: 'createSmtpProvider',
                    description: '/docs/references/messaging/create-smtp-provider.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_PROVIDER,
                        )
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'messaging.createSMTPProvider',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'messaging',
                    group: 'providers',
                    name: 'createSMTPProvider',
                    description: '/docs/references/messaging/create-smtp-provider.md',
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
            ->param('host', '', new Text(0), 'SMTP hosts. Either a single hostname or multiple semicolon-delimited hostnames. You can also specify a different port for each host such as `smtp1.example.com:25;smtp2.example.com`. You can also specify encryption type, for example: `tls://smtp1.example.com:587;ssl://smtp2.example.com:465"`. Hosts will be tried in order.')
            ->param('port', 587, new Range(1, 65535), 'The default SMTP server port.', true, example: '587')
            ->param('username', '', new Text(0), 'Authentication username.', true)
            ->param('password', '', new PasswordFormat(new Text(0)), 'Authentication password.', true)
            ->param('encryption', '', new WhiteList(['none', 'ssl', 'tls']), 'Encryption type. Can be omitted, \'ssl\', or \'tls\'', true, enum: new Enum(name: 'SmtpEncryption'))
            ->param('autoTLS', true, new Boolean(), 'Enable SMTP AutoTLS feature.', true)
            ->param('mailer', '', new Text(0), 'The value to use for the X-Mailer header.', true)
            ->param('fromName', '', new Text(128, 0), 'Sender Name.', true)
            ->param('fromEmail', '', new Email(), 'Sender email address.', true)
            ->param('replyToName', '', new Text(128, 0), 'Name set in the reply to field for the mail. Default value is sender name.', true)
            ->param('replyToEmail', '', new Email(), 'Email set in the reply to field for the mail. Default value is sender email.', true)
            ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
            ->inject('queueForEvents')
            ->inject('dbForProject')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(string $providerId, string $name, string $host, int $port, string $username, string $password, string $encryption, bool $autoTLS, string $mailer, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response)
    {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $credentials = [
            'port' => $port,
            'username' => $username,
            'password' => $password,
        ];

        if (!empty($host)) {
            $credentials['host'] = $host;
        }

        $options = [
            'fromName' => $fromName,
            'fromEmail' => $fromEmail,
            'replyToName' => $replyToName,
            'replyToEmail' => $replyToEmail,
            'encryption' => $encryption === 'none' ? '' : $encryption,
            'autoTLS' => $autoTLS,
            'mailer' => $mailer,
        ];

        if (
            $enabled === true
            && !empty($fromEmail)
            && \array_key_exists('host', $credentials)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'smtp',
            'type' => MESSAGE_TYPE_EMAIL,
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
