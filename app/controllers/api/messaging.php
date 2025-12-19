<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Detector\Detector;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Messaging;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Status as MessageStatus;
use Appwrite\Network\Validator\Email;
use Appwrite\Permission;
use Appwrite\Role;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CompoundUID;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Messages;
use Appwrite\Utopia\Database\Validator\Queries\Providers;
use Appwrite\Utopia\Database\Validator\Queries\Subscribers;
use Appwrite\Utopia\Database\Validator\Queries\Targets;
use Appwrite\Utopia\Database\Validator\Queries\Topics;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Roles;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\JSON;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

use function Swoole\Coroutine\batch;

App::post('/v1/messaging/providers/mailgun')
    ->desc('Create Mailgun provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'createMailgunProvider',
        description: '/docs/references/messaging/create-mailgun-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.', true)
    ->param('domain', '', new Text(0), 'Mailgun Domain.', true)
    ->param('isEuRegion', null, new Nullable(new Boolean()), 'Set as EU region.', true)
    ->param('fromName', '', new Text(128, 0), 'Sender Name.', true)
    ->param('fromEmail', '', new Email(), 'Sender email address.', true)
    ->param('replyToName', '', new Text(128, 0), 'Name set in the reply to field for the mail. Default value is sender name. Reply to name must have reply to email as well.', true)
    ->param('replyToEmail', '', new Email(), 'Email set in the reply to field for the mail. Default value is sender email. Reply to email must have reply to name as well.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $apiKey, string $domain, ?bool $isEuRegion, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $credentials = [];

        if (!\is_null($isEuRegion)) {
            $credentials['isEuRegion'] = $isEuRegion;
        }

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (!empty($domain)) {
            $credentials['domain'] = $domain;
        }

        $options = [
            'fromName' => $fromName,
            'fromEmail' => $fromEmail,
            'replyToName' => $replyToName,
            'replyToEmail' => $replyToEmail,
        ];

        if (
            $enabled === true
            && !empty($fromEmail)
            && \array_key_exists('isEuRegion', $credentials)
            && \array_key_exists('apiKey', $credentials)
            && \array_key_exists('domain', $credentials)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'mailgun',
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
    });

App::post('/v1/messaging/providers/sendgrid')
    ->desc('Create Sendgrid provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'createSendgridProvider',
        description: '/docs/references/messaging/create-sendgrid-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('apiKey', '', new Text(0), 'Sendgrid API key.', true)
    ->param('fromName', '', new Text(128, 0), 'Sender Name.', true)
    ->param('fromEmail', '', new Email(), 'Sender email address.', true)
    ->param('replyToName', '', new Text(128, 0), 'Name set in the reply to field for the mail. Default value is sender name.', true)
    ->param('replyToEmail', '', new Email(), 'Email set in the reply to field for the mail. Default value is sender email.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $apiKey, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $credentials = [];

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        $options = [
            'fromName' => $fromName,
            'fromEmail' => $fromEmail,
            'replyToName' => $replyToName,
            'replyToEmail' => $replyToEmail,
        ];

        if (
            $enabled === true
            && !empty($fromEmail)
            && \array_key_exists('apiKey', $credentials)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'sendgrid',
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
    });

App::post('/v1/messaging/providers/resend')
    ->desc('Create Resend provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'createResendProvider',
        description: '/docs/references/messaging/create-resend-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('apiKey', '', new Text(0), 'Resend API key.', true)
    ->param('fromName', '', new Text(128, 0), 'Sender Name.', true)
    ->param('fromEmail', '', new Email(), 'Sender email address.', true)
    ->param('replyToName', '', new Text(128, 0), 'Name set in the reply to field for the mail. Default value is sender name.', true)
    ->param('replyToEmail', '', new Email(), 'Email set in the reply to field for the mail. Default value is sender email.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $apiKey, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $credentials = [];

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        $options = [
            'fromName' => $fromName,
            'fromEmail' => $fromEmail,
            'replyToName' => $replyToName,
            'replyToEmail' => $replyToEmail,
        ];

        if (
            $enabled === true
            && !empty($fromEmail)
            && \array_key_exists('apiKey', $credentials)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'resend',
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
    });

App::post('/v1/messaging/providers/smtp')
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
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('host', '', new Text(0), 'SMTP hosts. Either a single hostname or multiple semicolon-delimited hostnames. You can also specify a different port for each host such as `smtp1.example.com:25;smtp2.example.com`. You can also specify encryption type, for example: `tls://smtp1.example.com:587;ssl://smtp2.example.com:465"`. Hosts will be tried in order.')
    ->param('port', 587, new Range(1, 65535), 'The default SMTP server port.', true)
    ->param('username', '', new Text(0), 'Authentication username.', true)
    ->param('password', '', new Text(0), 'Authentication password.', true)
    ->param('encryption', '', new WhiteList(['none', 'ssl', 'tls']), 'Encryption type. Can be omitted, \'ssl\', or \'tls\'', true)
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
    ->action(function (string $providerId, string $name, string $host, int $port, string $username, string $password, string $encryption, bool $autoTLS, string $mailer, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
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
    });

App::post('/v1/messaging/providers/msg91')
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
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('templateId', '', new Text(0), 'Msg91 template ID', true)
    ->param('senderId', '', new Text(0), 'Msg91 sender ID.', true)
    ->param('authKey', '', new Text(0), 'Msg91 auth key.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $templateId, string $senderId, string $authKey, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
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
            && \array_key_exists('from', $options)
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
    });

App::post('/v1/messaging/providers/telesign')
    ->desc('Create Telesign provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'createTelesignProvider',
        description: '/docs/references/messaging/create-telesign-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Phone(), 'Sender Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('customerId', '', new Text(0), 'Telesign customer ID.', true)
    ->param('apiKey', '', new Text(0), 'Telesign API key.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $from, string $customerId, string $apiKey, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $options = [];

        if (!empty($from)) {
            $options['from'] = $from;
        }

        $credentials = [];

        if (!empty($customerId)) {
            $credentials['customerId'] = $customerId;
        }

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (
            $enabled === true
            && \array_key_exists('customerId', $credentials)
            && \array_key_exists('apiKey', $credentials)
            && \array_key_exists('from', $options)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'telesign',
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
    });

App::post('/v1/messaging/providers/textmagic')
    ->desc('Create Textmagic provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'createTextmagicProvider',
        description: '/docs/references/messaging/create-textmagic-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Phone(), 'Sender Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('username', '', new Text(0), 'Textmagic username.', true)
    ->param('apiKey', '', new Text(0), 'Textmagic apiKey.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $from, string $username, string $apiKey, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $options = [];

        if (!empty($from)) {
            $options['from'] = $from;
        }

        $credentials = [];

        if (!empty($username)) {
            $credentials['username'] = $username;
        }

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (
            $enabled === true
            && \array_key_exists('username', $credentials)
            && \array_key_exists('apiKey', $credentials)
            && \array_key_exists('from', $options)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'textmagic',
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
    });

App::post('/v1/messaging/providers/twilio')
    ->desc('Create Twilio provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'createTwilioProvider',
        description: '/docs/references/messaging/create-twilio-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Phone(), 'Sender Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('accountSid', '', new Text(0), 'Twilio account secret ID.', true)
    ->param('authToken', '', new Text(0), 'Twilio authentication token.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $from, string $accountSid, string $authToken, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $options = [];

        if (!empty($from)) {
            $options['from'] = $from;
        }

        $credentials = [];

        if (!empty($accountSid)) {
            $credentials['accountSid'] = $accountSid;
        }

        if (!empty($authToken)) {
            $credentials['authToken'] = $authToken;
        }

        if (
            $enabled === true
            && \array_key_exists('accountSid', $credentials)
            && \array_key_exists('authToken', $credentials)
            && \array_key_exists('from', $options)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'twilio',
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
    });

App::post('/v1/messaging/providers/vonage')
    ->desc('Create Vonage provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'createVonageProvider',
        description: '/docs/references/messaging/create-vonage-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Phone(), 'Sender Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('apiKey', '', new Text(0), 'Vonage API key.', true)
    ->param('apiSecret', '', new Text(0), 'Vonage API secret.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $from, string $apiKey, string $apiSecret, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $options = [];

        if (!empty($from)) {
            $options['from'] = $from;
        }

        $credentials = [];

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (!empty($apiSecret)) {
            $credentials['apiSecret'] = $apiSecret;
        }

        if (
            $enabled === true
            && \array_key_exists('apiKey', $credentials)
            && \array_key_exists('apiSecret', $credentials)
            && \array_key_exists('from', $options)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'vonage',
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
    });

App::post('/v1/messaging/providers/fcm')
    ->desc('Create FCM provider')
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
            name: 'createFcmProvider',
            description: '/docs/references/messaging/create-fcm-provider.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_PROVIDER,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'messaging.createFCMProvider',
            ),
            public: false,
        ),
        new Method(
            namespace: 'messaging',
            group: 'providers',
            name: 'createFCMProvider',
            description: '/docs/references/messaging/create-fcm-provider.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_PROVIDER,
                )
            ]
        )
    ])
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('serviceAccountJSON', null, new Nullable(new JSON()), 'FCM service account JSON.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, array|string|null $serviceAccountJSON, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $serviceAccountJSON = \is_string($serviceAccountJSON)
            ? \json_decode($serviceAccountJSON, true)
            : $serviceAccountJSON;

        $credentials = [];

        if (!\is_null($serviceAccountJSON)) {
            $credentials['serviceAccountJSON'] = $serviceAccountJSON;
        }

        if ($enabled === true && \array_key_exists('serviceAccountJSON', $credentials)) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'fcm',
            'type' => MESSAGE_TYPE_PUSH,
            'enabled' => $enabled,
            'credentials' => $credentials
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
    });

App::post('/v1/messaging/providers/apns')
    ->desc('Create APNS provider')
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
            name: 'createApnsProvider',
            description: '/docs/references/messaging/create-apns-provider.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_PROVIDER,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'messaging.createAPNSProvider',
            ),
            public: false,
        ),
        new Method(
            namespace: 'messaging',
            group: 'providers',
            name: 'createAPNSProvider',
            description: '/docs/references/messaging/create-apns-provider.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_PROVIDER,
                )
            ]
        )
    ])
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('authKey', '', new Text(0), 'APNS authentication key.', true)
    ->param('authKeyId', '', new Text(0), 'APNS authentication key ID.', true)
    ->param('teamId', '', new Text(0), 'APNS team ID.', true)
    ->param('bundleId', '', new Text(0), 'APNS bundle ID.', true)
    ->param('sandbox', false, new Boolean(), 'Use APNS sandbox environment.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $authKey, string $authKeyId, string $teamId, string $bundleId, bool $sandbox, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $credentials = [];

        if (!empty($authKey)) {
            $credentials['authKey'] = $authKey;
        }

        if (!empty($authKeyId)) {
            $credentials['authKeyId'] = $authKeyId;
        }

        if (!empty($teamId)) {
            $credentials['teamId'] = $teamId;
        }

        if (!empty($bundleId)) {
            $credentials['bundleId'] = $bundleId;
        }

        if (
            $enabled === true
            && \array_key_exists('authKey', $credentials)
            && \array_key_exists('authKeyId', $credentials)
            && \array_key_exists('teamId', $credentials)
            && \array_key_exists('bundleId', $credentials)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $options = [
            'sandbox' => $sandbox
        ];

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'apns',
            'type' => MESSAGE_TYPE_PUSH,
            'enabled' => $enabled,
            'credentials' => $credentials,
            'options' => $options
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
    });

App::get('/v1/messaging/providers')
    ->desc('List providers')
    ->groups(['api', 'messaging'])
    ->label('scope', 'providers.read')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'listProviders',
        description: '/docs/references/messaging/list-providers.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER_LIST,
            )
        ]
    ))
    ->param('queries', [], new Providers(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Providers::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('response')
    ->action(function (array $queries, string $search, bool $includeTotal, Database $dbForProject, Authorization $authorization, Response $response) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $providerId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('providers', $providerId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Provider '{$providerId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }
        try {
            $providers = $dbForProject->find('providers', $queries);
            $total = $includeTotal ? $dbForProject->count('providers', $queries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $response->dynamic(new Document([
            'providers' => $providers,
            'total' => $total,
        ]), Response::MODEL_PROVIDER_LIST);
    });

App::get('/v1/messaging/providers/:providerId/logs')
    ->desc('List provider logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'providers.read')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'listProviderLogs',
        description: '/docs/references/messaging/list-provider-logs.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ]
    ))
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $providerId, array $queries, bool $includeTotal, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $audit = new Audit($dbForProject);
        $resource = 'provider/' . $providerId;
        $logs = $audit->getLogsByResource($resource, $queries);
        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => ID::custom($log['data']['userId']),
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $includeTotal ? $audit->countLogsByResource($resource, $queries) : 0,
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::get('/v1/messaging/providers/:providerId')
    ->desc('Get provider')
    ->groups(['api', 'messaging'])
    ->label('scope', 'providers.read')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'getProvider',
        description: '/docs/references/messaging/get-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $response->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/mailgun/:providerId')
    ->desc('Update Mailgun provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'updateMailgunProvider',
        description: '/docs/references/messaging/update-mailgun-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.', true)
    ->param('domain', '', new Text(0), 'Mailgun Domain.', true)
    ->param('isEuRegion', null, new Nullable(new Boolean()), 'Set as EU region.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->param('fromName', '', new Text(128), 'Sender Name.', true)
    ->param('fromEmail', '', new Email(), 'Sender email address.', true)
    ->param('replyToName', '', new Text(128), 'Name set in the reply to field for the mail. Default value is sender name.', true)
    ->param('replyToEmail', '', new Text(128), 'Email set in the reply to field for the mail. Default value is sender email.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $apiKey, string $domain, ?bool $isEuRegion, ?bool $enabled, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, Event $queueForEvents, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $providerProvider = $provider->getAttribute('provider');

        if ($providerProvider !== 'mailgun') {
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

        $credentials = $provider->getAttribute('credentials');

        if (!\is_null($isEuRegion)) {
            $credentials['isEuRegion'] = $isEuRegion;
        }

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (!empty($domain)) {
            $credentials['domain'] = $domain;
        }

        $provider->setAttribute('credentials', $credentials);

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    \array_key_exists('isEuRegion', $credentials) &&
                    \array_key_exists('apiKey', $credentials) &&
                    \array_key_exists('domain', $credentials) &&
                    \array_key_exists('fromEmail', $options)
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
    });

App::patch('/v1/messaging/providers/sendgrid/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
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
    ->action(function (string $providerId, string $name, ?bool $enabled, string $apiKey, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, Event $queueForEvents, Database $dbForProject, Response $response) {
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
    });

App::patch('/v1/messaging/providers/resend/:providerId')
    ->desc('Update Resend provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'updateResendProvider',
        description: '/docs/references/messaging/update-resend-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->param('apiKey', '', new Text(0), 'Resend API key.', true)
    ->param('fromName', '', new Text(128), 'Sender Name.', true)
    ->param('fromEmail', '', new Email(), 'Sender email address.', true)
    ->param('replyToName', '', new Text(128), 'Name set in the Reply To field for the mail. Default value is Sender Name.', true)
    ->param('replyToEmail', '', new Text(128), 'Email set in the Reply To field for the mail. Default value is Sender Email.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $apiKey, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, Event $queueForEvents, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'resend') {
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
    });

App::patch('/v1/messaging/providers/smtp/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('host', '', new Text(0), 'SMTP hosts. Either a single hostname or multiple semicolon-delimited hostnames. You can also specify a different port for each host such as `smtp1.example.com:25;smtp2.example.com`. You can also specify encryption type, for example: `tls://smtp1.example.com:587;ssl://smtp2.example.com:465"`. Hosts will be tried in order.', true)
    ->param('port', null, new Nullable(new Range(1, 65535)), 'SMTP port.', true)
    ->param('username', '', new Text(0), 'Authentication username.', true)
    ->param('password', '', new Text(0), 'Authentication password.', true)
    ->param('encryption', '', new WhiteList(['none', 'ssl', 'tls']), 'Encryption type. Can be \'ssl\' or \'tls\'', true)
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
    ->action(function (string $providerId, string $name, string $host, ?int $port, string $username, string $password, string $encryption, ?bool $autoTLS, string $mailer, string $fromName, string $fromEmail, string $replyToName, string $replyToEmail, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
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
    });

App::patch('/v1/messaging/providers/msg91/:providerId')
    ->desc('Update Msg91 provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'updateMsg91Provider',
        description: '/docs/references/messaging/update-msg91-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->param('templateId', '', new Text(0), 'Msg91 template ID.', true)
    ->param('senderId', '', new Text(0), 'Msg91 sender ID.', true)
    ->param('authKey', '', new Text(0), 'Msg91 auth key.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $templateId, string $senderId, string $authKey, Event $queueForEvents, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'msg91') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
        }

        $credentials = $provider->getAttribute('credentials');

        if (!empty($templateId)) {
            $credentials['templateId'] = $templateId;
        }

        if (!empty($senderId)) {
            $credentials['senderId'] = $senderId;
        }

        if (!empty($authKey)) {
            $credentials['authKey'] = $authKey;
        }

        $provider->setAttribute('credentials', $credentials);

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    \array_key_exists('senderId', $credentials) &&
                    \array_key_exists('authKey', $credentials) &&
                    \array_key_exists('templateId', $credentials)
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
    });

App::patch('/v1/messaging/providers/telesign/:providerId')
    ->desc('Update Telesign provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'updateTelesignProvider',
        description: '/docs/references/messaging/update-telesign-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->param('customerId', '', new Text(0), 'Telesign customer ID.', true)
    ->param('apiKey', '', new Text(0), 'Telesign API key.', true)
    ->param('from', '', new Text(256), 'Sender number.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $customerId, string $apiKey, string $from, Event $queueForEvents, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'telesign') {
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

        if (!empty($customerId)) {
            $credentials['customerId'] = $customerId;
        }

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        $provider->setAttribute('credentials', $credentials);

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    \array_key_exists('customerId', $credentials) &&
                    \array_key_exists('apiKey', $credentials) &&
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
    });

App::patch('/v1/messaging/providers/textmagic/:providerId')
    ->desc('Update Textmagic provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'updateTextmagicProvider',
        description: '/docs/references/messaging/update-textmagic-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->param('username', '', new Text(0), 'Textmagic username.', true)
    ->param('apiKey', '', new Text(0), 'Textmagic apiKey.', true)
    ->param('from', '', new Text(256), 'Sender number.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $username, string $apiKey, string $from, Event $queueForEvents, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'textmagic') {
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

        if (!empty($username)) {
            $credentials['username'] = $username;
        }

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        $provider->setAttribute('credentials', $credentials);

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    \array_key_exists('username', $credentials) &&
                    \array_key_exists('apiKey', $credentials) &&
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
    });

App::patch('/v1/messaging/providers/twilio/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->param('accountSid', '', new Text(0), 'Twilio account secret ID.', true)
    ->param('authToken', '', new Text(0), 'Twilio authentication token.', true)
    ->param('from', '', new Text(256), 'Sender number.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $accountSid, string $authToken, string $from, Event $queueForEvents, Database $dbForProject, Response $response) {
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
    });

App::patch('/v1/messaging/providers/vonage/:providerId')
    ->desc('Update Vonage provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'updateVonageProvider',
        description: '/docs/references/messaging/update-vonage-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROVIDER,
            )
        ]
    ))
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->param('apiKey', '', new Text(0), 'Vonage API key.', true)
    ->param('apiSecret', '', new Text(0), 'Vonage API secret.', true)
    ->param('from', '', new Text(256), 'Sender number.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $apiKey, string $apiSecret, string $from, Event $queueForEvents, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'vonage') {
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

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (!empty($apiSecret)) {
            $credentials['apiSecret'] = $apiSecret;
        }

        $provider->setAttribute('credentials', $credentials);

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    \array_key_exists('apiKey', $credentials) &&
                    \array_key_exists('apiSecret', $credentials) &&
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
    });

App::patch('/v1/messaging/providers/fcm/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->param('serviceAccountJSON', null, new Nullable(new JSON()), 'FCM service account JSON.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, array|string|null $serviceAccountJSON, Event $queueForEvents, Database $dbForProject, Response $response) {
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
                : $serviceAccountJSON;

            $provider->setAttribute('credentials', [
                'serviceAccountJSON' => $serviceAccountJSON
            ]);
        }

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (\array_key_exists('serviceAccountJSON', $provider->getAttribute('credentials'))) {
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
    });


App::patch('/v1/messaging/providers/apns/:providerId')
    ->desc('Update APNS provider')
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
            name: 'updateApnsProvider',
            description: '/docs/references/messaging/update-apns-provider.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_PROVIDER,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'messaging.updateAPNSProvider',
            ),
            public: false,
        ),
        new Method(
            namespace: 'messaging',
            group: 'providers',
            name: 'updateAPNSProvider',
            description: '/docs/references/messaging/update-apns-provider.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_PROVIDER,
                )
            ]
        )
    ])
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Set as enabled.', true)
    ->param('authKey', '', new Text(0), 'APNS authentication key.', true)
    ->param('authKeyId', '', new Text(0), 'APNS authentication key ID.', true)
    ->param('teamId', '', new Text(0), 'APNS team ID.', true)
    ->param('bundleId', '', new Text(0), 'APNS bundle ID.', true)
    ->param('sandbox', null, new Nullable(new Boolean()), 'Use APNS sandbox environment.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $authKey, string $authKeyId, string $teamId, string $bundleId, ?bool $sandbox, Event $queueForEvents, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'apns') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
        }

        $credentials = $provider->getAttribute('credentials');

        if (!empty($authKey)) {
            $credentials['authKey'] = $authKey;
        }

        if (!empty($authKeyId)) {
            $credentials['authKeyId'] = $authKeyId;
        }

        if (!empty($teamId)) {
            $credentials['teamId'] = $teamId;
        }

        if (!empty($bundleId)) {
            $credentials['bundle'] = $bundleId;
        }

        $provider->setAttribute('credentials', $credentials);

        $options = $provider->getAttribute('options');

        if (!\is_null($sandbox)) {
            $options['sandbox'] = $sandbox;
        }

        $provider->setAttribute('options', $options);

        if (!\is_null($enabled)) {
            if ($enabled) {
                if (
                    \array_key_exists('authKey', $credentials) &&
                    \array_key_exists('authKeyId', $credentials) &&
                    \array_key_exists('teamId', $credentials) &&
                    \array_key_exists('bundleId', $credentials)
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
    });

App::delete('/v1/messaging/providers/:providerId')
    ->desc('Delete provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.delete')
    ->label('audits.resource', 'provider/{request.$providerId}')
    ->label('event', 'providers.[providerId].delete')
    ->label('scope', 'providers.write')
    ->label('resourceType', RESOURCE_TYPE_PROVIDERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'providers',
        name: 'deleteProvider',
        description: '/docs/references/messaging/delete-provider.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, Event $queueForEvents, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $dbForProject->deleteDocument('providers', $provider->getId());

        $queueForEvents
            ->setParam('providerId', $provider->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_NOCONTENT)
            ->noContent();
    });

App::post('/v1/messaging/topics')
    ->desc('Create topic')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'topic.create')
    ->label('audits.resource', 'topic/{response.$id}')
    ->label('event', 'topics.[topicId].create')
    ->label('scope', 'topics.write')
    ->label('resourceType', RESOURCE_TYPE_TOPICS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'topics',
        name: 'createTopic',
        description: '/docs/references/messaging/create-topic.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_TOPIC,
            )
        ]
    ))
    ->param('topicId', '', new CustomId(), 'Topic ID. Choose a custom Topic ID or a new Topic ID.')
    ->param('name', '', new Text(128), 'Topic Name.')
    ->param('subscribe', [Role::users()], new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of role strings with subscribe permission. By default all users are granted with any subscribe permission. [learn more about roles](https://appwrite.io/docs/permissions#permission-roles). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $topicId, string $name, array $subscribe, Event $queueForEvents, Database $dbForProject, Response $response) {
        $topicId = $topicId == 'unique()' ? ID::unique() : $topicId;

        $topic = new Document([
            '$id' => $topicId,
            'name' => $name,
            'subscribe' => $subscribe,
        ]);

        try {
            $topic = $dbForProject->createDocument('topics', $topic);
        } catch (DuplicateException) {
            throw new Exception(Exception::TOPIC_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('topicId', $topic->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($topic, Response::MODEL_TOPIC);
    });

App::get('/v1/messaging/topics')
    ->desc('List topics')
    ->groups(['api', 'messaging'])
    ->label('scope', 'topics.read')
    ->label('resourceType', RESOURCE_TYPE_TOPICS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'topics',
        name: 'listTopics',
        description: '/docs/references/messaging/list-topics.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TOPIC_LIST,
            )
        ]
    ))
    ->param('queries', [], new Topics(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Topics::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('response')
    ->action(function (array $queries, string $search, bool $includeTotal, Database $dbForProject, Authorization $authorization, Response $response) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $topicId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Topic '{$topicId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument[0]);
        }
        try {
            $topics = $dbForProject->find('topics', $queries);
            $total = $includeTotal ? $dbForProject->count('topics', $queries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $response->dynamic(new Document([
            'topics' => $topics,
            'total' => $total,
        ]), Response::MODEL_TOPIC_LIST);
    });

App::get('/v1/messaging/topics/:topicId/logs')
    ->desc('List topic logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'topics.read')
    ->label('resourceType', RESOURCE_TYPE_TOPICS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'topics',
        name: 'listTopicLogs',
        description: '/docs/references/messaging/list-topic-logs.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ]
    ))
    ->param('topicId', '', new UID(), 'Topic ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $topicId, array $queries, bool $includeTotal, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $audit = new Audit($dbForProject);
        $resource = 'topic/' . $topicId;
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => ID::custom($log['data']['userId']),
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $includeTotal ? $audit->countLogsByResource($resource, $queries) : 0,
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::get('/v1/messaging/topics/:topicId')
    ->desc('Get topic')
    ->groups(['api', 'messaging'])
    ->label('scope', 'topics.read')
    ->label('resourceType', RESOURCE_TYPE_TOPICS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'topics',
        name: 'getTopic',
        description: '/docs/references/messaging/get-topic.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TOPIC,
            )
        ]
    ))
    ->param('topicId', '', new UID(), 'Topic ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $topicId, Database $dbForProject, Response $response) {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $response
            ->dynamic($topic, Response::MODEL_TOPIC);
    });

App::patch('/v1/messaging/topics/:topicId')
    ->desc('Update topic')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'topic.update')
    ->label('audits.resource', 'topic/{response.$id}')
    ->label('event', 'topics.[topicId].update')
    ->label('scope', 'topics.write')
    ->label('resourceType', RESOURCE_TYPE_TOPICS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'topics',
        name: 'updateTopic',
        description: '/docs/references/messaging/update-topic.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TOPIC,
            )
        ]
    ))
    ->param('topicId', '', new UID(), 'Topic ID.')
    ->param('name', null, new Nullable(new Text(128)), 'Topic Name.', true)
    ->param('subscribe', null, new Nullable(new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE)), 'An array of role strings with subscribe permission. By default all users are granted with any subscribe permission. [learn more about roles](https://appwrite.io/docs/permissions#permission-roles). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $topicId, ?string $name, ?array $subscribe, Event $queueForEvents, Database $dbForProject, Response $response) {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        if (!\is_null($name)) {
            $topic->setAttribute('name', $name);
        }

        if (!\is_null($subscribe)) {
            $topic->setAttribute('subscribe', $subscribe);
        }

        $topic = $dbForProject->updateDocument('topics', $topicId, $topic);

        $queueForEvents
            ->setParam('topicId', $topic->getId());

        $response
            ->dynamic($topic, Response::MODEL_TOPIC);
    });

App::delete('/v1/messaging/topics/:topicId')
    ->desc('Delete topic')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'topic.delete')
    ->label('audits.resource', 'topic/{request.$topicId}')
    ->label('event', 'topics.[topicId].delete')
    ->label('scope', 'topics.write')
    ->label('resourceType', RESOURCE_TYPE_TOPICS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'topics',
        name: 'deleteTopic',
        description: '/docs/references/messaging/delete-topic.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('topicId', '', new UID(), 'Topic ID.')
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('queueForDeletes')
    ->inject('response')
    ->action(function (string $topicId, Event $queueForEvents, Database $dbForProject, Delete $queueForDeletes, Response $response) {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $dbForProject->deleteDocument('topics', $topicId);

        $queueForDeletes
            ->setType(DELETE_TYPE_TOPIC)
            ->setDocument($topic);

        $queueForEvents
            ->setParam('topicId', $topic->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_NOCONTENT)
            ->noContent();
    });

App::post('/v1/messaging/topics/:topicId/subscribers')
    ->desc('Create subscriber')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'subscriber.create')
    ->label('audits.resource', 'subscriber/{response.$id}')
    ->label('event', 'topics.[topicId].subscribers.[subscriberId].create')
    ->label('scope', 'subscribers.write')
    ->label('resourceType', RESOURCE_TYPE_SUBSCRIBERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'subscribers',
        name: 'createSubscriber',
        description: '/docs/references/messaging/create-subscriber.md',
        auth: [AuthType::JWT, AuthType::SESSION, AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_SUBSCRIBER,
            )
        ]
    ))
    ->param('subscriberId', '', new CustomId(), 'Subscriber ID. Choose a custom Subscriber ID or a new Subscriber ID.')
    ->param('topicId', '', new UID(), 'Topic ID. The topic ID to subscribe to.')
    ->param('targetId', '', new UID(), 'Target ID. The target ID to link to the specified Topic ID.')
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('response')
    ->action(function (string $subscriberId, string $topicId, string $targetId, Event $queueForEvents, Database $dbForProject, Authorization $authorization, Response $response) {
        $subscriberId = $subscriberId == 'unique()' ? ID::unique() : $subscriberId;

        $topic = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }
        if (!$authorization->isValid(new Input('subscribe', $topic->getAttribute('subscribe')))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $target->getAttribute('userId')));

        $subscriber = new Document([
            '$id' => $subscriberId,
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ],
            'topicId' => $topicId,
            'topicInternalId' => $topic->getSequence(),
            'targetId' => $targetId,
            'targetInternalId' => $target->getSequence(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'providerType' => $target->getAttribute('providerType'),
            'search' => implode(' ', [
                $subscriberId,
                $targetId,
                $user->getId(),
                $target->getAttribute('providerType'),
            ]),
        ]);

        try {
            $subscriber = $dbForProject->createDocument('subscribers', $subscriber);

            $totalAttribute = match ($target->getAttribute('providerType')) {
                MESSAGE_TYPE_EMAIL => 'emailTotal',
                MESSAGE_TYPE_SMS => 'smsTotal',
                MESSAGE_TYPE_PUSH => 'pushTotal',
                default => throw new Exception(Exception::TARGET_PROVIDER_INVALID_TYPE),
            };

            $authorization->skip(fn () => $dbForProject->increaseDocumentAttribute(
                'topics',
                $topicId,
                $totalAttribute,
            ));
        } catch (DuplicateException) {
            throw new Exception(Exception::SUBSCRIBER_ALREADY_EXISTS);
        }

        $queueForEvents
            ->setParam('topicId', $topic->getId())
            ->setParam('subscriberId', $subscriber->getId());

        $subscriber
            ->setAttribute('target', $target)
            ->setAttribute('userName', $user->getAttribute('name'));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($subscriber, Response::MODEL_SUBSCRIBER);
    });

App::get('/v1/messaging/topics/:topicId/subscribers')
    ->desc('List subscribers')
    ->groups(['api', 'messaging'])
    ->label('scope', 'subscribers.read')
    ->label('resourceType', RESOURCE_TYPE_SUBSCRIBERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'subscribers',
        name: 'listSubscribers',
        description: '/docs/references/messaging/list-subscribers.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_SUBSCRIBER_LIST,
            )
        ]
    ))
    ->param('topicId', '', new UID(), 'Topic ID. The topic ID subscribed to.')
    ->param('queries', [], new Subscribers(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Providers::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('response')
    ->action(function (string $topicId, array $queries, string $search, bool $includeTotal, Database $dbForProject, Authorization $authorization, Response $response) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        $topic = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $queries[] = Query::equal('topicInternalId', [$topic->getSequence()]);

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $subscriberId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('subscribers', $subscriberId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Subscriber '{$subscriberId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }
        try {
            $subscribers = $dbForProject->find('subscribers', $queries);
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }

        $subscribers = batch(\array_map(function (Document $subscriber) use ($dbForProject, $authorization) {
            return function () use ($subscriber, $dbForProject, $authorization) {
                $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $subscriber->getAttribute('targetId')));
                $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $target->getAttribute('userId')));

                return $subscriber
                    ->setAttribute('target', $target)
                    ->setAttribute('userName', $user->getAttribute('name'));
            };
        }, $subscribers));

        $response
            ->dynamic(new Document([
                'subscribers' => $subscribers,
                'total' => $includeTotal ? $dbForProject->count('subscribers', $queries, APP_LIMIT_COUNT) : 0,
            ]), Response::MODEL_SUBSCRIBER_LIST);
    });

App::get('/v1/messaging/subscribers/:subscriberId/logs')
    ->desc('List subscriber logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'subscribers.read')
    ->label('resourceType', RESOURCE_TYPE_SUBSCRIBERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'subscribers',
        name: 'listSubscriberLogs',
        description: '/docs/references/messaging/list-subscriber-logs.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ]
    ))
    ->param('subscriberId', '', new UID(), 'Subscriber ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $subscriberId, array $queries, bool $includeTotal, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $subscriber = $dbForProject->getDocument('subscribers', $subscriberId);

        if ($subscriber->isEmpty()) {
            throw new Exception(Exception::SUBSCRIBER_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $audit = new Audit($dbForProject);
        $resource = 'subscriber/' . $subscriberId;
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => ID::custom($log['data']['userId']),
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $includeTotal ? $audit->countLogsByResource($resource, $queries) : 0,
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::get('/v1/messaging/topics/:topicId/subscribers/:subscriberId')
    ->desc('Get subscriber')
    ->groups(['api', 'messaging'])
    ->label('scope', 'subscribers.read')
    ->label('resourceType', RESOURCE_TYPE_SUBSCRIBERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'subscribers',
        name: 'getSubscriber',
        description: '/docs/references/messaging/get-subscriber.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_SUBSCRIBER,
            )
        ]
    ))
    ->param('topicId', '', new UID(), 'Topic ID. The topic ID subscribed to.')
    ->param('subscriberId', '', new UID(), 'Subscriber ID.')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('response')
    ->action(function (string $topicId, string $subscriberId, Database $dbForProject, Authorization $authorization, Response $response) {
        $topic = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $subscriber = $dbForProject->getDocument('subscribers', $subscriberId);

        if ($subscriber->isEmpty() || $subscriber->getAttribute('topicId') !== $topicId) {
            throw new Exception(Exception::SUBSCRIBER_NOT_FOUND);
        }

        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $subscriber->getAttribute('targetId')));
        $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $target->getAttribute('userId')));

        $subscriber
            ->setAttribute('target', $target)
            ->setAttribute('userName', $user->getAttribute('name'));

        $response
            ->dynamic($subscriber, Response::MODEL_SUBSCRIBER);
    });

App::delete('/v1/messaging/topics/:topicId/subscribers/:subscriberId')
    ->desc('Delete subscriber')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'subscriber.delete')
    ->label('audits.resource', 'subscriber/{request.$subscriberId}')
    ->label('event', 'topics.[topicId].subscribers.[subscriberId].delete')
    ->label('scope', 'subscribers.write')
    ->label('resourceType', RESOURCE_TYPE_SUBSCRIBERS)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'subscribers',
        name: 'deleteSubscriber',
        description: '/docs/references/messaging/delete-subscriber.md',
        auth: [AuthType::JWT, AuthType::SESSION, AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('topicId', '', new UID(), 'Topic ID. The topic ID subscribed to.')
    ->param('subscriberId', '', new UID(), 'Subscriber ID.')
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('response')
    ->action(function (string $topicId, string $subscriberId, Event $queueForEvents, Database $dbForProject, Authorization $authorization, Response $response) {
        $topic = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $subscriber = $dbForProject->getDocument('subscribers', $subscriberId);

        if ($subscriber->isEmpty() || $subscriber->getAttribute('topicId') !== $topicId) {
            throw new Exception(Exception::SUBSCRIBER_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('targets', $subscriber->getAttribute('targetId'));

        $dbForProject->deleteDocument('subscribers', $subscriberId);

        $totalAttribute = match ($target->getAttribute('providerType')) {
            MESSAGE_TYPE_EMAIL => 'emailTotal',
            MESSAGE_TYPE_SMS => 'smsTotal',
            MESSAGE_TYPE_PUSH => 'pushTotal',
            default => throw new Exception(Exception::TARGET_PROVIDER_INVALID_TYPE),
        };

        $authorization->skip(fn () => $dbForProject->decreaseDocumentAttribute(
            'topics',
            $topicId,
            $totalAttribute,
            min: 0
        ));

        $queueForEvents
            ->setParam('topicId', $topic->getId())
            ->setParam('subscriberId', $subscriber->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_NOCONTENT)
            ->noContent();
    });

App::post('/v1/messaging/messages/email')
    ->desc('Create email')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.create')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].create')
    ->label('scope', 'messages.write')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'messages',
        name: 'createEmail',
        description: '/docs/references/messaging/create-email.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_MESSAGE,
            )
        ]
    ))
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('subject', '', new Text(998), 'Email Subject.')
    ->param('content', '', new Text(64230), 'Email Content.')
    ->param('topics', [], new ArrayList(new UID()), 'List of Topic IDs.', true)
    ->param('users', [], new ArrayList(new UID()), 'List of User IDs.', true)
    ->param('targets', [], new ArrayList(new UID()), 'List of Targets IDs.', true)
    ->param('cc', [], new ArrayList(new UID()), 'Array of target IDs to be added as CC.', true)
    ->param('bcc', [], new ArrayList(new UID()), 'Array of target IDs to be added as BCC.', true)
    ->param('attachments', [], new ArrayList(new CompoundUID()), 'Array of compound ID strings of bucket IDs and file IDs to be attached to the email. They should be formatted as <BUCKET_ID>:<FILE_ID>.', true)
    ->param('draft', false, new Boolean(), 'Is message a draft', true)
    ->param('html', false, new Boolean(), 'Is content of type HTML', true)
    ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, string $subject, string $content, ?array $topics, ?array $users, ?array $targets, ?array $cc, ?array $bcc, ?array $attachments, bool $draft, bool $html, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, Messaging $queueForMessaging, Response $response) {
        $messageId = $messageId == 'unique()'
            ? ID::unique()
            : $messageId;

        if ($draft) {
            $status = MessageStatus::DRAFT;
        } else {
            $status = \is_null($scheduledAt)
                ? MessageStatus::PROCESSING
                : MessageStatus::SCHEDULED;
        }

        if ($status !== MessageStatus::DRAFT && \count($topics) === 0 && \count($users) === 0 && \count($targets) === 0) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        if ($status === MessageStatus::SCHEDULED && \is_null($scheduledAt)) {
            throw new Exception(Exception::MESSAGE_MISSING_SCHEDULE);
        }

        $mergedTargets = \array_merge($targets, $cc, $bcc);

        if (!empty($mergedTargets)) {
            $foundTargets = $dbForProject->find('targets', [
                Query::equal('$id', $mergedTargets),
                Query::equal('providerType', [MESSAGE_TYPE_EMAIL]),
                Query::limit(\count($mergedTargets)),
            ]);

            if (\count($foundTargets) !== \count($mergedTargets)) {
                throw new Exception(Exception::MESSAGE_TARGET_NOT_EMAIL);
            }

            foreach ($foundTargets as $target) {
                if ($target->isEmpty()) {
                    throw new Exception(Exception::USER_TARGET_NOT_FOUND);
                }
            }
        }

        if (!empty($attachments)) {
            foreach ($attachments as &$attachment) {
                [$bucketId, $fileId] = CompoundUID::parse($attachment);

                $bucket = $dbForProject->getDocument('buckets', $bucketId);

                if ($bucket->isEmpty()) {
                    throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                }

                $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);

                if ($file->isEmpty()) {
                    throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
                }

                $attachment = [
                    'bucketId' => $bucketId,
                    'fileId' => $fileId,
                ];
            }
        }

        $message = $dbForProject->createDocument('messages', new Document([
            '$id' => $messageId,
            'providerType' => MESSAGE_TYPE_EMAIL,
            'topics' => $topics,
            'users' => $users,
            'targets' => $targets,
            'scheduledAt' => $scheduledAt,
            'data' => [
                'subject' => $subject,
                'content' => $content,
                'html' => $html,
                'cc' => $cc,
                'bcc' => $bcc,
                'attachments' => $attachments,
            ],
            'status' => $status,
        ]));

        switch ($status) {
            case MessageStatus::PROCESSING:
                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                    ->setMessageId($message->getId());
                break;
            case MessageStatus::SCHEDULED:
                $schedule = $dbForPlatform->createDocument('schedules', new Document([
                    'region' => $project->getAttribute('region'),
                    'resourceType' => SCHEDULE_RESOURCE_TYPE_MESSAGE,
                    'resourceId' => $message->getId(),
                    'resourceInternalId' => $message->getSequence(),
                    'resourceUpdatedAt' => DateTime::now(),
                    'projectId' => $project->getId(),
                    'schedule' => $scheduledAt,
                    'active' => true,
                ]));

                $message->setAttribute('scheduleId', $schedule->getId());

                $dbForProject->updateDocument(
                    'messages',
                    $message->getId(),
                    $message
                );
                break;
            default:
                break;
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::post('/v1/messaging/messages/sms')
    ->desc('Create SMS')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.create')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].create')
    ->label('scope', 'messages.write')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', [
        new Method(
            namespace: 'messaging',
            group: 'messages',
            name: 'createSms',
            description: '/docs/references/messaging/create-sms.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_MESSAGE,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'messaging.createSMS',
            ),
            public: false,
        ),
        new Method(
            namespace: 'messaging',
            group: 'messages',
            name: 'createSMS',
            description: '/docs/references/messaging/create-sms.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_MESSAGE,
                )
            ]
        )
    ])
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('content', '', new Text(64230), 'SMS Content.')
    ->param('topics', [], new ArrayList(new UID()), 'List of Topic IDs.', true)
    ->param('users', [], new ArrayList(new UID()), 'List of User IDs.', true)
    ->param('targets', [], new ArrayList(new UID()), 'List of Targets IDs.', true)
    ->param('draft', false, new Boolean(), 'Is message a draft', true)
    ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, string $content, ?array $topics, ?array $users, ?array $targets, bool $draft, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, Messaging $queueForMessaging, Response $response) {
        $messageId = $messageId == 'unique()'
            ? ID::unique()
            : $messageId;

        if ($draft) {
            $status = MessageStatus::DRAFT;
        } else {
            $status = \is_null($scheduledAt)
                ? MessageStatus::PROCESSING
                : MessageStatus::SCHEDULED;
        }

        if ($status !== MessageStatus::DRAFT && \count($topics) === 0 && \count($users) === 0 && \count($targets) === 0) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        if ($status === MessageStatus::SCHEDULED && \is_null($scheduledAt)) {
            throw new Exception(Exception::MESSAGE_MISSING_SCHEDULE);
        }

        if (!empty($targets)) {
            $foundTargets = $dbForProject->find('targets', [
                Query::equal('$id', $targets),
                Query::equal('providerType', [MESSAGE_TYPE_SMS]),
                Query::limit(\count($targets)),
            ]);

            if (\count($foundTargets) !== \count($targets)) {
                throw new Exception(Exception::MESSAGE_TARGET_NOT_SMS);
            }

            foreach ($foundTargets as $target) {
                if ($target->isEmpty()) {
                    throw new Exception(Exception::USER_TARGET_NOT_FOUND);
                }
            }
        }

        $message = $dbForProject->createDocument('messages', new Document([
            '$id' => $messageId,
            'providerType' => MESSAGE_TYPE_SMS,
            'topics' => $topics,
            'users' => $users,
            'targets' => $targets,
            'data' => [
                'content' => $content,
            ],
            'status' => $status,
        ]));

        switch ($status) {
            case MessageStatus::PROCESSING:
                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                    ->setMessageId($message->getId());
                break;
            case MessageStatus::SCHEDULED:
                $schedule = $dbForPlatform->createDocument('schedules', new Document([
                    'region' => $project->getAttribute('region'),
                    'resourceType' => SCHEDULE_RESOURCE_TYPE_MESSAGE,
                    'resourceId' => $message->getId(),
                    'resourceInternalId' => $message->getSequence(),
                    'resourceUpdatedAt' => DateTime::now(),
                    'projectId' => $project->getId(),
                    'schedule' => $scheduledAt,
                    'active' => true,
                ]));

                $message->setAttribute('scheduleId', $schedule->getId());

                $dbForProject->updateDocument(
                    'messages',
                    $message->getId(),
                    $message
                );
                break;
            default:
                break;
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::post('/v1/messaging/messages/push')
    ->desc('Create push notification')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.create')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].create')
    ->label('scope', 'messages.write')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'messages',
        name: 'createPush',
        description: '/docs/references/messaging/create-push.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_MESSAGE,
            )
        ]
    ))
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('title', '', new Text(256), 'Title for push notification.', true)
    ->param('body', '', new Text(64230), 'Body for push notification.', true)
    ->param('topics', [], new ArrayList(new UID()), 'List of Topic IDs.', true)
    ->param('users', [], new ArrayList(new UID()), 'List of User IDs.', true)
    ->param('targets', [], new ArrayList(new UID()), 'List of Targets IDs.', true)
    ->param('data', null, new Nullable(new JSON()), 'Additional key-value pair data for push notification.', true)
    ->param('action', '', new Text(256), 'Action for push notification.', true)
    ->param('image', '', new CompoundUID(), 'Image for push notification. Must be a compound bucket ID to file ID of a jpeg, png, or bmp image in Appwrite Storage. It should be formatted as <BUCKET_ID>:<FILE_ID>.', true)
    ->param('icon', '', new Text(256), 'Icon for push notification. Available only for Android and Web Platform.', true)
    ->param('sound', '', new Text(256), 'Sound for push notification. Available only for Android and iOS Platform.', true)
    ->param('color', '', new Text(256), 'Color for push notification. Available only for Android Platform.', true)
    ->param('tag', '', new Text(256), 'Tag for push notification. Available only for Android Platform.', true)
    ->param('badge', -1, new Integer(), 'Badge for push notification. Available only for iOS Platform.', true)
    ->param('draft', false, new Boolean(), 'Is message a draft', true)
    ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->param('contentAvailable', false, new Boolean(), 'If set to true, the notification will be delivered in the background. Available only for iOS Platform.', true)
    ->param('critical', false, new Boolean(), 'If set to true, the notification will be marked as critical. This requires the app to have the critical notification entitlement. Available only for iOS Platform.', true)
    ->param('priority', 'high', new WhiteList(['normal', 'high']), 'Set the notification priority. "normal" will consider device state and may not deliver notifications immediately. "high" will always attempt to immediately deliver the notification.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->inject('platform')
    ->action(function (string $messageId, string $title, string $body, ?array $topics, ?array $users, ?array $targets, ?array $data, string $action, string $image, string $icon, string $sound, string $color, string $tag, int $badge, bool $draft, ?string $scheduledAt, bool $contentAvailable, bool $critical, string $priority, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, Messaging $queueForMessaging, Response $response, array $platform) {
        $messageId = $messageId == 'unique()'
            ? ID::unique()
            : $messageId;

        if ($draft) {
            $status = MessageStatus::DRAFT;
        } else {
            $status = \is_null($scheduledAt)
                ? MessageStatus::PROCESSING
                : MessageStatus::SCHEDULED;
        }

        if ($status !== MessageStatus::DRAFT && \count($topics) === 0 && \count($users) === 0 && \count($targets) === 0) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        if ($status === MessageStatus::SCHEDULED && \is_null($scheduledAt)) {
            throw new Exception(Exception::MESSAGE_MISSING_SCHEDULE);
        }

        if (!empty($targets)) {
            $foundTargets = $dbForProject->find('targets', [
                Query::equal('$id', $targets),
                Query::equal('providerType', [MESSAGE_TYPE_PUSH]),
                Query::limit(\count($targets)),
            ]);

            if (\count($foundTargets) !== \count($targets)) {
                throw new Exception(Exception::MESSAGE_TARGET_NOT_PUSH);
            }

            foreach ($foundTargets as $target) {
                if ($target->isEmpty()) {
                    throw new Exception(Exception::USER_TARGET_NOT_FOUND);
                }
            }
        }

        if (!empty($image)) {
            [$bucketId, $fileId] = CompoundUID::parse($image);

            $bucket = $dbForProject->getDocument('buckets', $bucketId);
            if ($bucket->isEmpty()) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
            if ($file->isEmpty()) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            if (!\in_array($file->getAttribute('mimeType'), ['image/png', 'image/jpeg'])) {
                throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED);
            }

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $endpoint = "$protocol://{$platform['apiHostname']}/v1";

            $scheduleTime = $currentScheduledAt ?? $scheduledAt;
            if (!\is_null($scheduleTime)) {
                $expiry = (new \DateTime($scheduleTime))->add(new \DateInterval('P15D'))->format('U');
            } else {
                $expiry = (new \DateTime())->add(new \DateInterval('P15D'))->format('U');
            }

            $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', \intval($expiry), 0);

            $jwt = $encoder->encode([
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'projectId' => $project->getId(),
            ]);

            $image = [
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'url' => "{$endpoint}/storage/buckets/{$bucket->getId()}/files/{$file->getId()}/push?project={$project->getId()}&jwt={$jwt}",
            ];
        }

        $pushData = [];

        if (!empty($title)) {
            $pushData['title'] = $title;
        }
        if (!empty($body)) {
            $pushData['body'] = $body;
        }
        if (!empty($data)) {
            $pushData['data'] = $data;
        }
        if (!empty($action)) {
            $pushData['action'] = $action;
        }
        if (!empty($image)) {
            $pushData['image'] = $image;
        }
        if (!empty($icon)) {
            $pushData['icon'] = $icon;
        }
        if (!empty($sound)) {
            $pushData['sound'] = $sound;
        }
        if (!empty($color)) {
            $pushData['color'] = $color;
        }
        if (!empty($tag)) {
            $pushData['tag'] = $tag;
        }
        if ($badge >= 0) {
            $pushData['badge'] = $badge;
        }
        if ($contentAvailable) {
            $pushData['contentAvailable'] = true;
        }
        if ($critical) {
            $pushData['critical'] = true;
        }
        if (!empty($priority)) {
            $pushData['priority'] = $priority;
        }

        $message = $dbForProject->createDocument('messages', new Document([
            '$id' => $messageId,
            'providerType' => MESSAGE_TYPE_PUSH,
            'topics' => $topics,
            'users' => $users,
            'targets' => $targets,
            'scheduledAt' => $scheduledAt,
            'data' => $pushData,
            'status' => $status,
        ]));

        switch ($status) {
            case MessageStatus::PROCESSING:
                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                    ->setMessageId($message->getId());
                break;
            case MessageStatus::SCHEDULED:
                $schedule = $dbForPlatform->createDocument('schedules', new Document([
                    'region' => $project->getAttribute('region'),
                    'resourceType' => SCHEDULE_RESOURCE_TYPE_MESSAGE,
                    'resourceId' => $message->getId(),
                    'resourceInternalId' => $message->getSequence(),
                    'resourceUpdatedAt' => DateTime::now(),
                    'projectId' => $project->getId(),
                    'schedule' => $scheduledAt,
                    'active' => true,
                ]));

                $message->setAttribute('scheduleId', $schedule->getId());

                $dbForProject->updateDocument(
                    'messages',
                    $message->getId(),
                    $message
                );
                break;
            default:
                break;
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::get('/v1/messaging/messages')
    ->desc('List messages')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'messages',
        name: 'listMessages',
        description: '/docs/references/messaging/list-messages.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MESSAGE_LIST,
            )
        ],
    ))
    ->param('queries', [], new Messages(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Messages::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('dbForProject')
    ->inject('authorization')
    ->inject('response')
    ->action(function (array $queries, string $search, bool $includeTotal, Database $dbForProject, Authorization $authorization, Response $response) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $messageId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('messages', $messageId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Message '{$messageId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }
        try {
            $messages = $dbForProject->find('messages', $queries);
            $total = $includeTotal ? $dbForProject->count('messages', $queries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $response->dynamic(new Document([
            'messages' => $messages,
            'total' => $total,
        ]), Response::MODEL_MESSAGE_LIST);
    });

App::get('/v1/messaging/messages/:messageId/logs')
    ->desc('List message logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'logs',
        name: 'listMessageLogs',
        description: '/docs/references/messaging/list-message-logs.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ],
    ))
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $messageId, array $queries, bool $includeTotal, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $audit = new Audit($dbForProject);
        $resource = 'message/' . $messageId;
        $logs = $audit->getLogsByResource($resource, $queries);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => ID::custom($log['data']['userId']),
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $includeTotal ? $audit->countLogsByResource($resource, $queries) : 0,
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::get('/v1/messaging/messages/:messageId/targets')
    ->desc('List message targets')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'messages',
        name: 'listTargets',
        description: '/docs/references/messaging/list-message-targets.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TARGET_LIST,
            )
        ],
    ))
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('queries', [], new Targets(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Targets::ALLOWED_ATTRIBUTES), true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $messageId, array $queries, bool $includeTotal, Response $response, Database $dbForProject) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        $targetIDs = $message->getAttribute('targets');

        if (empty($targetIDs)) {
            $response->dynamic(new Document([
                'targets' => [],
                'total' => 0,
            ]), Response::MODEL_TARGET_LIST);
            return;
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('$id', $targetIDs);

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $targetId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('targets', $targetId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Target '{$targetId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }
        try {
            $targets = $dbForProject->find('targets', $queries);
            $total = $includeTotal ? $dbForProject->count('targets', $queries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $response->dynamic(new Document([
            'targets' => $targets,
            'total' => $total,
        ]), Response::MODEL_TARGET_LIST);
    });

App::get('/v1/messaging/messages/:messageId')
    ->desc('Get message')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'messages',
        name: 'getMessage',
        description: '/docs/references/messaging/get-message.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MESSAGE,
            )
        ]
    ))
    ->param('messageId', '', new UID(), 'Message ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $messageId, Database $dbForProject, Response $response) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        $response->dynamic($message, Response::MODEL_MESSAGE);
    });

App::patch('/v1/messaging/messages/email/:messageId')
    ->desc('Update email')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.update')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].update')
    ->label('scope', 'messages.write')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'messages',
        name: 'updateEmail',
        description: '/docs/references/messaging/update-email.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MESSAGE,
            )
        ]
    ))
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('topics', null, new Nullable(new ArrayList(new UID())), 'List of Topic IDs.', true)
    ->param('users', null, new Nullable(new ArrayList(new UID())), 'List of User IDs.', true)
    ->param('targets', null, new Nullable(new ArrayList(new UID())), 'List of Targets IDs.', true)
    ->param('subject', null, new Nullable(new Text(998)), 'Email Subject.', true)
    ->param('content', null, new Nullable(new Text(64230)), 'Email Content.', true)
    ->param('draft', null, new Nullable(new Boolean()), 'Is message a draft', true)
    ->param('html', null, new Nullable(new Boolean()), 'Is content of type HTML', true)
    ->param('cc', null, new Nullable(new ArrayList(new UID())), 'Array of target IDs to be added as CC.', true)
    ->param('bcc', null, new Nullable(new ArrayList(new UID())), 'Array of target IDs to be added as BCC.', true)
    ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->param('attachments', null, new Nullable(new ArrayList(new CompoundUID())), 'Array of compound ID strings of bucket IDs and file IDs to be attached to the email. They should be formatted as <BUCKET_ID>:<FILE_ID>.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, ?array $topics, ?array $users, ?array $targets, ?string $subject, ?string $content, ?bool $draft, ?bool $html, ?array $cc, ?array $bcc, ?string $scheduledAt, ?array $attachments, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, Messaging $queueForMessaging, Response $response) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        if (!\is_null($draft) || !\is_null($scheduledAt)) {
            if ($draft) {
                $status = MessageStatus::DRAFT;
            } else {
                $status = \is_null($scheduledAt)
                    ? MessageStatus::PROCESSING
                    : MessageStatus::SCHEDULED;
            }
        } else {
            $status = $message->getAttribute('status');
        }

        if (
            $status !== MessageStatus::DRAFT
            && \count($topics ?? $message->getAttribute('topics', [])) === 0
            && \count($users ?? $message->getAttribute('users', [])) === 0
            && \count($targets ?? $message->getAttribute('targets', [])) === 0
        ) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        $currentScheduledAt = $message->getAttribute('scheduledAt');

        switch ($message->getAttribute('status')) {
            case MessageStatus::PROCESSING:
                throw new Exception(Exception::MESSAGE_ALREADY_PROCESSING);
            case MessageStatus::SENT:
                throw new Exception(Exception::MESSAGE_ALREADY_SENT);
            case MessageStatus::FAILED:
                throw new Exception(Exception::MESSAGE_ALREADY_FAILED);
        }

        if (
            $status === MessageStatus::SCHEDULED
            && \is_null($scheduledAt)
            && \is_null($currentScheduledAt)
        ) {
            throw new Exception(Exception::MESSAGE_MISSING_SCHEDULE);
        }

        if (!\is_null($currentScheduledAt) && new \DateTime($currentScheduledAt) < new \DateTime()) {
            throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
        }

        if (\is_null($currentScheduledAt) && !\is_null($scheduledAt)) {
            $schedule = $dbForPlatform->createDocument('schedules', new Document([
                'region' => $project->getAttribute('region'),
                'resourceType' => SCHEDULE_RESOURCE_TYPE_MESSAGE,
                'resourceId' => $message->getId(),
                'resourceInternalId' => $message->getSequence(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'schedule' => $scheduledAt,
                'active' => $status === MessageStatus::SCHEDULED,
            ]));

            $message->setAttribute('scheduleId', $schedule->getId());
        }

        if (!\is_null($currentScheduledAt)) {
            $schedule = $dbForPlatform->getDocument('schedules', $message->getAttribute('scheduleId'));
            $scheduledStatus = ($status ?? $message->getAttribute('status')) === MessageStatus::SCHEDULED;

            if ($schedule->isEmpty()) {
                throw new Exception(Exception::SCHEDULE_NOT_FOUND);
            }

            $schedule
                ->setAttribute('resourceUpdatedAt', DateTime::now())
                ->setAttribute('active', $scheduledStatus);

            if (!\is_null($scheduledAt)) {
                $schedule->setAttribute('schedule', $scheduledAt);
            }

            $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule);
        }

        if (!\is_null($scheduledAt)) {
            $message->setAttribute('scheduledAt', $scheduledAt);
        }

        if (!\is_null($topics)) {
            $message->setAttribute('topics', $topics);
        }

        if (!\is_null($users)) {
            $message->setAttribute('users', $users);
        }

        if (!\is_null($targets)) {
            $message->setAttribute('targets', $targets);
        }

        $data = $message->getAttribute('data');

        if (!\is_null($subject)) {
            $data['subject'] = $subject;
        }

        if (!\is_null($content)) {
            $data['content'] = $content;
        }

        if (!is_null($attachments)) {
            foreach ($attachments as &$attachment) {
                [$bucketId, $fileId] = CompoundUID::parse($attachment);

                $bucket = $dbForProject->getDocument('buckets', $bucketId);

                if ($bucket->isEmpty()) {
                    throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
                }

                $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);

                if ($file->isEmpty()) {
                    throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
                }

                $attachment = [
                    'bucketId' => $bucketId,
                    'fileId' => $fileId,
                ];
            }
            $data['attachments'] = $attachments;
        }

        if (!\is_null($html)) {
            $data['html'] = $html;
        }

        if (!\is_null($cc)) {
            $data['cc'] = $cc;
        }

        if (!\is_null($bcc)) {
            $data['bcc'] = $bcc;
        }

        $message->setAttribute('data', $data);

        if (!\is_null($status)) {
            $message->setAttribute('status', $status);
        }

        $message = $dbForProject->updateDocument('messages', $message->getId(), $message);

        if ($status === MessageStatus::PROCESSING) {
            $queueForMessaging
                ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                ->setMessageId($message->getId());
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::patch('/v1/messaging/messages/sms/:messageId')
    ->desc('Update SMS')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.update')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].update')
    ->label('scope', 'messages.write')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', [
        new Method(
            namespace: 'messaging',
            group: 'messages',
            name: 'updateSms',
            description: '/docs/references/messaging/update-sms.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_MESSAGE,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'messaging.updateSMS',
            ),
            public: false,
        ),
        new Method(
            namespace: 'messaging',
            group: 'messages',
            name: 'updateSMS',
            description: '/docs/references/messaging/update-sms.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_MESSAGE,
                )
            ]
        )
    ])
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('topics', null, new Nullable(new ArrayList(new UID())), 'List of Topic IDs.', true)
    ->param('users', null, new Nullable(new ArrayList(new UID())), 'List of User IDs.', true)
    ->param('targets', null, new Nullable(new ArrayList(new UID())), 'List of Targets IDs.', true)
    ->param('content', null, new Nullable(new Text(64230)), 'Email Content.', true)
    ->param('draft', null, new Nullable(new Boolean()), 'Is message a draft', true)
    ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, ?array $topics, ?array $users, ?array $targets, ?string $content, ?bool $draft, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, Messaging $queueForMessaging, Response $response) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        if (!\is_null($draft) || !\is_null($scheduledAt)) {
            if ($draft) {
                $status = MessageStatus::DRAFT;
            } else {
                $status = \is_null($scheduledAt)
                    ? MessageStatus::PROCESSING
                    : MessageStatus::SCHEDULED;
            }
        } else {
            $status = $message->getAttribute('status');
        }

        if (
            $status !== MessageStatus::DRAFT
            && \count($topics ?? $message->getAttribute('topics', [])) === 0
            && \count($users ?? $message->getAttribute('users', [])) === 0
            && \count($targets ?? $message->getAttribute('targets', [])) === 0
        ) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        $currentScheduledAt = $message->getAttribute('scheduledAt');

        switch ($message->getAttribute('status')) {
            case MessageStatus::PROCESSING:
                throw new Exception(Exception::MESSAGE_ALREADY_PROCESSING);
            case MessageStatus::SENT:
                throw new Exception(Exception::MESSAGE_ALREADY_SENT);
            case MessageStatus::FAILED:
                throw new Exception(Exception::MESSAGE_ALREADY_FAILED);
        }

        if (
            $status === MessageStatus::SCHEDULED
            && \is_null($scheduledAt)
            && \is_null($currentScheduledAt)
        ) {
            throw new Exception(Exception::MESSAGE_MISSING_SCHEDULE);
        }

        if (!\is_null($currentScheduledAt) && new \DateTime($currentScheduledAt) < new \DateTime()) {
            throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
        }

        if (\is_null($currentScheduledAt) && !\is_null($scheduledAt)) {
            $schedule = $dbForPlatform->createDocument('schedules', new Document([
                'region' => $project->getAttribute('region'),
                'resourceType' => SCHEDULE_RESOURCE_TYPE_MESSAGE,
                'resourceId' => $message->getId(),
                'resourceInternalId' => $message->getSequence(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'schedule' => $scheduledAt,
                'active' => $status === MessageStatus::SCHEDULED,
            ]));

            $message->setAttribute('scheduleId', $schedule->getId());
        }

        if (!\is_null($currentScheduledAt)) {
            $schedule = $dbForPlatform->getDocument('schedules', $message->getAttribute('scheduleId'));
            $scheduledStatus = ($status ?? $message->getAttribute('status')) === MessageStatus::SCHEDULED;

            if ($schedule->isEmpty()) {
                throw new Exception(Exception::SCHEDULE_NOT_FOUND);
            }

            $schedule
                ->setAttribute('resourceUpdatedAt', DateTime::now())
                ->setAttribute('active', $scheduledStatus);

            if (!\is_null($scheduledAt)) {
                $schedule->setAttribute('schedule', $scheduledAt);
            }

            $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule);
        }

        if (!\is_null($scheduledAt)) {
            $message->setAttribute('scheduledAt', $scheduledAt);
        }

        if (!\is_null($topics)) {
            $message->setAttribute('topics', $topics);
        }

        if (!\is_null($users)) {
            $message->setAttribute('users', $users);
        }

        if (!\is_null($targets)) {
            $message->setAttribute('targets', $targets);
        }

        $data = $message->getAttribute('data');

        if (!\is_null($content)) {
            $data['content'] = $content;
        }

        $message->setAttribute('data', $data);

        if (!\is_null($status)) {
            $message->setAttribute('status', $status);
        }

        $message = $dbForProject->updateDocument('messages', $message->getId(), $message);

        if ($status === MessageStatus::PROCESSING) {
            $queueForMessaging
                ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                ->setMessageId($message->getId());
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::patch('/v1/messaging/messages/push/:messageId')
    ->desc('Update push notification')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.update')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].update')
    ->label('scope', 'messages.write')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'messages',
        name: 'updatePush',
        description: '/docs/references/messaging/update-push.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MESSAGE,
            )
        ]
    ))
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('topics', null, new Nullable(new ArrayList(new UID())), 'List of Topic IDs.', true)
    ->param('users', null, new Nullable(new ArrayList(new UID())), 'List of User IDs.', true)
    ->param('targets', null, new Nullable(new ArrayList(new UID())), 'List of Targets IDs.', true)
    ->param('title', null, new Nullable(new Text(256)), 'Title for push notification.', true)
    ->param('body', null, new Nullable(new Text(64230)), 'Body for push notification.', true)
    ->param('data', null, new Nullable(new JSON()), 'Additional Data for push notification.', true)
    ->param('action', null, new Nullable(new Text(256)), 'Action for push notification.', true)
    ->param('image', null, new Nullable(new CompoundUID()), 'Image for push notification. Must be a compound bucket ID to file ID of a jpeg, png, or bmp image in Appwrite Storage. It should be formatted as <BUCKET_ID>:<FILE_ID>.', true)
    ->param('icon', null, new Nullable(new Text(256)), 'Icon for push notification. Available only for Android and Web platforms.', true)
    ->param('sound', null, new Nullable(new Text(256)), 'Sound for push notification. Available only for Android and iOS platforms.', true)
    ->param('color', null, new Nullable(new Text(256)), 'Color for push notification. Available only for Android platforms.', true)
    ->param('tag', null, new Nullable(new Text(256)), 'Tag for push notification. Available only for Android platforms.', true)
    ->param('badge', null, new Nullable(new Integer()), 'Badge for push notification. Available only for iOS platforms.', true)
    ->param('draft', null, new Nullable(new Boolean()), 'Is message a draft', true)
    ->param('scheduledAt', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->param('contentAvailable', null, new Nullable(new Boolean()), 'If set to true, the notification will be delivered in the background. Available only for iOS Platform.', true)
    ->param('critical', null, new Nullable(new Boolean()), 'If set to true, the notification will be marked as critical. This requires the app to have the critical notification entitlement. Available only for iOS Platform.', true)
    ->param('priority', null, new Nullable(new WhiteList(['normal', 'high'])), 'Set the notification priority. "normal" will consider device battery state and may send notifications later. "high" will always attempt to immediately deliver the notification.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->inject('platform')
    ->action(function (string $messageId, ?array $topics, ?array $users, ?array $targets, ?string $title, ?string $body, ?array $data, ?string $action, ?string $image, ?string $icon, ?string $sound, ?string $color, ?string $tag, ?int $badge, ?bool $draft, ?string $scheduledAt, ?bool $contentAvailable, ?bool $critical, ?string $priority, Event $queueForEvents, Database $dbForProject, Database $dbForPlatform, Document $project, Messaging $queueForMessaging, Response $response, array $platform) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        if (!\is_null($draft) || !\is_null($scheduledAt)) {
            if ($draft) {
                $status = MessageStatus::DRAFT;
            } else {
                $status = \is_null($scheduledAt)
                    ? MessageStatus::PROCESSING
                    : MessageStatus::SCHEDULED;
            }
        } else {
            $status = $message->getAttribute('status');
        }

        if (
            $status !== MessageStatus::DRAFT
            && \count($topics ?? $message->getAttribute('topics', [])) === 0
            && \count($users ?? $message->getAttribute('users', [])) === 0
            && \count($targets ?? $message->getAttribute('targets', [])) === 0
        ) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        $currentScheduledAt = $message->getAttribute('scheduledAt');

        switch ($message->getAttribute('status')) {
            case MessageStatus::PROCESSING:
                throw new Exception(Exception::MESSAGE_ALREADY_PROCESSING);
            case MessageStatus::SENT:
                throw new Exception(Exception::MESSAGE_ALREADY_SENT);
            case MessageStatus::FAILED:
                throw new Exception(Exception::MESSAGE_ALREADY_FAILED);
        }

        if (
            $status === MessageStatus::SCHEDULED
            && \is_null($scheduledAt)
            && \is_null($currentScheduledAt)
        ) {
            throw new Exception(Exception::MESSAGE_MISSING_SCHEDULE);
        }

        if (!\is_null($currentScheduledAt) && new \DateTime($currentScheduledAt) < new \DateTime()) {
            throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
        }

        if (\is_null($currentScheduledAt) && !\is_null($scheduledAt)) {
            $schedule = $dbForPlatform->createDocument('schedules', new Document([
                'region' => $project->getAttribute('region'),
                'resourceType' => SCHEDULE_RESOURCE_TYPE_MESSAGE,
                'resourceId' => $message->getId(),
                'resourceInternalId' => $message->getSequence(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'schedule' => $scheduledAt,
                'active' => $status === MessageStatus::SCHEDULED,
            ]));

            $message->setAttribute('scheduleId', $schedule->getId());
        }

        if (!\is_null($currentScheduledAt)) {
            $schedule = $dbForPlatform->getDocument('schedules', $message->getAttribute('scheduleId'));
            $scheduledStatus = ($status ?? $message->getAttribute('status')) === MessageStatus::SCHEDULED;

            if ($schedule->isEmpty()) {
                throw new Exception(Exception::SCHEDULE_NOT_FOUND);
            }

            $schedule
                ->setAttribute('resourceUpdatedAt', DateTime::now())
                ->setAttribute('active', $scheduledStatus);

            if (!\is_null($scheduledAt)) {
                $schedule->setAttribute('schedule', $scheduledAt);
            }

            $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule);
        }

        if (!\is_null($scheduledAt)) {
            $message->setAttribute('scheduledAt', $scheduledAt);
        }

        if (!\is_null($topics)) {
            $message->setAttribute('topics', $topics);
        }

        if (!\is_null($users)) {
            $message->setAttribute('users', $users);
        }

        if (!\is_null($targets)) {
            $message->setAttribute('targets', $targets);
        }

        $pushData = $message->getAttribute('data');

        if (!\is_null($title)) {
            $pushData['title'] = $title;
        }

        if (!\is_null($body)) {
            $pushData['body'] = $body;
        }

        if (!\is_null($data)) {
            $pushData['data'] = $data;
        }

        if (!\is_null($action)) {
            $pushData['action'] = $action;
        }

        if (!\is_null($icon)) {
            $pushData['icon'] = $icon;
        }

        if (!\is_null($sound)) {
            $pushData['sound'] = $sound;
        }

        if (!\is_null($color)) {
            $pushData['color'] = $color;
        }

        if (!\is_null($tag)) {
            $pushData['tag'] = $tag;
        }

        if (!\is_null($badge)) {
            $pushData['badge'] = $badge;
        }

        if (!\is_null($contentAvailable)) {
            $pushData['contentAvailable'] = $contentAvailable;
        }

        if (!\is_null($critical)) {
            $pushData['critical'] = $critical;
        }

        if (!\is_null($priority)) {
            $pushData['priority'] = $priority;
        }

        if (!\is_null($image)) {
            [$bucketId, $fileId] = CompoundUID::parse($image);

            $bucket = $dbForProject->getDocument('buckets', $bucketId);
            if ($bucket->isEmpty()) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
            if ($file->isEmpty()) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            if (!\in_array($file->getAttribute('mimeType'), ['image/png', 'image/jpeg'])) {
                throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED);
            }

            $scheduleTime = $currentScheduledAt ?? $scheduledAt;
            if (!\is_null($scheduleTime)) {
                $expiry = (new \DateTime($scheduleTime))->add(new \DateInterval('P15D'))->format('U');
            } else {
                $expiry = (new \DateTime())->add(new \DateInterval('P15D'))->format('U');
            }

            $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', \intval($expiry), 0);

            $jwt = $encoder->encode([
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'projectId' => $project->getId(),
            ]);

            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') == 'disabled' ? 'http' : 'https';
            $endpoint = "$protocol://{$platform['apiHostname']}/v1";

            $pushData['image'] = [
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'url' => "{$endpoint}/storage/buckets/{$bucket->getId()}/files/{$file->getId()}/push?project={$project->getId()}&jwt={$jwt}",
            ];
        }

        $message->setAttribute('data', $pushData);

        if (!\is_null($status)) {
            $message->setAttribute('status', $status);
        }

        $message = $dbForProject->updateDocument('messages', $message->getId(), $message);

        if ($status === MessageStatus::PROCESSING) {
            $queueForMessaging
                ->setType(MESSAGE_SEND_TYPE_EXTERNAL)
                ->setMessageId($message->getId());
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::delete('/v1/messaging/messages/:messageId')
    ->desc('Delete message')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.delete')
    ->label('audits.resource', 'message/{request.messageId}')
    ->label('event', 'messages.[messageId].delete')
    ->label('scope', 'messages.write')
    ->label('resourceType', RESOURCE_TYPE_MESSAGES)
    ->label('sdk', new Method(
        namespace: 'messaging',
        group: 'messages',
        name: 'delete',
        description: '/docs/references/messaging/delete-message.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('messageId', '', new UID(), 'Message ID.')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('queueForEvents')
    ->inject('response')
    ->action(function (string $messageId, Database $dbForProject, Database $dbForPlatform, Event $queueForEvents, Response $response) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        switch ($message->getAttribute('status')) {
            case MessageStatus::PROCESSING:
                throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
            case MessageStatus::SCHEDULED:
                $scheduleId = $message->getAttribute('scheduleId');
                $scheduledAt = $message->getAttribute('scheduledAt');

                $now = DateTime::now();
                $scheduledDate = DateTime::formatTz($scheduledAt);

                if ($now > $scheduledDate) {
                    throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
                }

                if (!empty($scheduleId)) {
                    try {
                        $dbForPlatform->deleteDocument('schedules', $scheduleId);
                    } catch (Exception) {
                        // Ignore
                    }
                }
                break;
            default:
                break;
        }

        $dbForProject->deleteDocument('messages', $message->getId());

        $queueForEvents
            ->setParam('messageId', $message->getId())
            ->setPayload($response->output($message, Response::MODEL_MESSAGE));

        $response->noContent();
    });
