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
use Appwrite\Utopia\Database\Validator\CompoundUID;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Messages;
use Appwrite\Utopia\Database\Validator\Queries\Providers;
use Appwrite\Utopia\Database\Validator\Queries\Subscribers;
use Appwrite\Utopia\Database\Validator\Queries\Targets;
use Appwrite\Utopia\Database\Validator\Queries\Topics;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\Audit\Audit;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Roles;
use Utopia\Database\Validator\UID;
use Utopia\Http\Http;
use Utopia\Http\Validator\ArrayList;
use Utopia\Http\Validator\Boolean;
use Utopia\Http\Validator\Integer;
use Utopia\Http\Validator\JSON;
use Utopia\Http\Validator\Range;
use Utopia\Http\Validator\Text;
use Utopia\Http\Validator\WhiteList;
use Utopia\Locale\Locale;
use Utopia\System\System;

use function Swoole\Coroutine\batch;

Http::post('/v1/messaging/providers/mailgun')
    ->desc('Create Mailgun provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createMailgunProvider')
    ->label('sdk.description', '/docs/references/messaging/create-mailgun-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.', true)
    ->param('domain', '', new Text(0), 'Mailgun Domain.', true)
    ->param('isEuRegion', null, new Boolean(), 'Set as EU region.', true)
    ->param('fromName', '', new Text(128, 0), 'Sender Name.', true)
    ->param('fromEmail', '', new Email(), 'Sender email address.', true)
    ->param('replyToName', '', new Text(128, 0), 'Name set in the reply to field for the mail. Default value is sender name. Reply to name must have reply to email as well.', true)
    ->param('replyToEmail', '', new Email(), 'Email set in the reply to field for the mail. Default value is sender email. Reply to email must have reply to name as well.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::post('/v1/messaging/providers/sendgrid')
    ->desc('Create Sendgrid provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createSendgridProvider')
    ->label('sdk.description', '/docs/references/messaging/create-sendgrid-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('apiKey', '', new Text(0), 'Sendgrid API key.', true)
    ->param('fromName', '', new Text(128, 0), 'Sender Name.', true)
    ->param('fromEmail', '', new Email(), 'Sender email address.', true)
    ->param('replyToName', '', new Text(128, 0), 'Name set in the reply to field for the mail. Default value is sender name.', true)
    ->param('replyToEmail', '', new Email(), 'Email set in the reply to field for the mail. Default value is sender email.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::post('/v1/messaging/providers/smtp')
    ->desc('Create SMTP provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createSmtpProvider')
    ->label('sdk.description', '/docs/references/messaging/create-smtp-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
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
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::post('/v1/messaging/providers/msg91')
    ->desc('Create Msg91 provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('event', 'providers.[providerId].create')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createMsg91Provider')
    ->label('sdk.description', '/docs/references/messaging/create-msg91-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Phone(), 'Sender Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('senderId', '', new Text(0), 'Msg91 Sender ID.', true)
    ->param('authKey', '', new Text(0), 'Msg91 Auth Key.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $from, string $senderId, string $authKey, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $options = [];

        if (!empty($from)) {
            $options['from'] = $from;
        }

        $credentials = [];

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

Http::post('/v1/messaging/providers/telesign')
    ->desc('Create Telesign provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createTelesignProvider')
    ->label('sdk.description', '/docs/references/messaging/create-telesign-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Phone(), 'Sender Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('customerId', '', new Text(0), 'Telesign customer ID.', true)
    ->param('apiKey', '', new Text(0), 'Telesign API key.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::post('/v1/messaging/providers/textmagic')
    ->desc('Create Textmagic provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createTextmagicProvider')
    ->label('sdk.description', '/docs/references/messaging/create-textmagic-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Phone(), 'Sender Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('username', '', new Text(0), 'Textmagic username.', true)
    ->param('apiKey', '', new Text(0), 'Textmagic apiKey.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::post('/v1/messaging/providers/twilio')
    ->desc('Create Twilio provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createTwilioProvider')
    ->label('sdk.description', '/docs/references/messaging/create-twilio-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Phone(), 'Sender Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('accountSid', '', new Text(0), 'Twilio account secret ID.', true)
    ->param('authToken', '', new Text(0), 'Twilio authentication token.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::post('/v1/messaging/providers/vonage')
    ->desc('Create Vonage provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createVonageProvider')
    ->label('sdk.description', '/docs/references/messaging/create-vonage-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Phone(), 'Sender Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('apiKey', '', new Text(0), 'Vonage API key.', true)
    ->param('apiSecret', '', new Text(0), 'Vonage API secret.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::post('/v1/messaging/providers/fcm')
    ->desc('Create FCM provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createFcmProvider')
    ->label('sdk.description', '/docs/references/messaging/create-fcm-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('serviceAccountJSON', null, new JSON(), 'FCM service account JSON.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::post('/v1/messaging/providers/apns')
    ->desc('Create APNS provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.create')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].create')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createApnsProvider')
    ->label('sdk.description', '/docs/references/messaging/create-apns-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('authKey', '', new Text(0), 'APNS authentication key.', true)
    ->param('authKeyId', '', new Text(0), 'APNS authentication key ID.', true)
    ->param('teamId', '', new Text(0), 'APNS team ID.', true)
    ->param('bundleId', '', new Text(0), 'APNS bundle ID.', true)
    ->param('sandbox', false, new Boolean(), 'Use APNS sandbox environment.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::get('/v1/messaging/providers')
    ->desc('List providers')
    ->groups(['api', 'messaging'])
    ->label('scope', 'providers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listProviders')
    ->label('sdk.description', '/docs/references/messaging/list-providers.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER_LIST)
    ->param('queries', [], new Providers(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Providers::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->inject('authorization')
    ->action(function (array $queries, string $search, Database $dbForProject, Response $response, Authorization $authorization) {
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
            $providerId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('providers', $providerId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Provider '{$providerId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $response->dynamic(new Document([
            'providers' => $dbForProject->find('providers', $queries),
            'total' => $dbForProject->count('providers', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_PROVIDER_LIST);
    });

Http::get('/v1/messaging/providers/:providerId/logs')
    ->desc('List provider logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'providers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listProviderLogs')
    ->label('sdk.description', '/docs/references/messaging/list-provider-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('authorization')
    ->action(function (string $providerId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb, Authorization $authorization) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject, $authorization);
        $resource = 'provider/' . $providerId;
        $logs = $audit->getLogsByResource($resource, $limit, $offset);
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
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

Http::get('/v1/messaging/providers/:providerId')
    ->desc('Get provider')
    ->groups(['api', 'messaging'])
    ->label('scope', 'providers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'getProvider')
    ->label('sdk.description', '/docs/references/messaging/get-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
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

Http::patch('/v1/messaging/providers/mailgun/:providerId')
    ->desc('Update Mailgun provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateMailgunProvider')
    ->label('sdk.description', '/docs/references/messaging/update-mailgun-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.', true)
    ->param('domain', '', new Text(0), 'Mailgun Domain.', true)
    ->param('isEuRegion', null, new Boolean(), 'Set as EU region.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::patch('/v1/messaging/providers/sendgrid/:providerId')
    ->desc('Update Sendgrid provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateSendgridProvider')
    ->label('sdk.description', '/docs/references/messaging/update-sendgrid-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::patch('/v1/messaging/providers/smtp/:providerId')
    ->desc('Update SMTP provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateSmtpProvider')
    ->label('sdk.description', '/docs/references/messaging/update-smtp-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('host', '', new Text(0), 'SMTP hosts. Either a single hostname or multiple semicolon-delimited hostnames. You can also specify a different port for each host such as `smtp1.example.com:25;smtp2.example.com`. You can also specify encryption type, for example: `tls://smtp1.example.com:587;ssl://smtp2.example.com:465"`. Hosts will be tried in order.', true)
    ->param('port', null, new Range(1, 65535), 'SMTP port.', true)
    ->param('username', '', new Text(0), 'Authentication username.', true)
    ->param('password', '', new Text(0), 'Authentication password.', true)
    ->param('encryption', '', new WhiteList(['none', 'ssl', 'tls']), 'Encryption type. Can be \'ssl\' or \'tls\'', true)
    ->param('autoTLS', null, new Boolean(), 'Enable SMTP AutoTLS feature.', true)
    ->param('mailer', '', new Text(0), 'The value to use for the X-Mailer header.', true)
    ->param('fromName', '', new Text(128), 'Sender Name.', true)
    ->param('fromEmail', '', new Email(), 'Sender email address.', true)
    ->param('replyToName', '', new Text(128), 'Name set in the Reply To field for the mail. Default value is Sender Name.', true)
    ->param('replyToEmail', '', new Text(128), 'Email set in the Reply To field for the mail. Default value is Sender Email.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::patch('/v1/messaging/providers/msg91/:providerId')
    ->desc('Update Msg91 provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateMsg91Provider')
    ->label('sdk.description', '/docs/references/messaging/update-msg91-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('senderId', '', new Text(0), 'Msg91 Sender ID.', true)
    ->param('authKey', '', new Text(0), 'Msg91 Auth Key.', true)
    ->param('from', '', new Text(256), 'Sender number.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $senderId, string $authKey, string $from, Event $queueForEvents, Database $dbForProject, Response $response) {
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

        if (!empty($from)) {
            $provider->setAttribute('options', [
                'from' => $from,
            ]);
        }

        $credentials = $provider->getAttribute('credentials');

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

Http::patch('/v1/messaging/providers/telesign/:providerId')
    ->desc('Update Telesign provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateTelesignProvider')
    ->label('sdk.description', '/docs/references/messaging/update-telesign-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::patch('/v1/messaging/providers/textmagic/:providerId')
    ->desc('Update Textmagic provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateTextmagicProvider')
    ->label('sdk.description', '/docs/references/messaging/update-textmagic-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::patch('/v1/messaging/providers/twilio/:providerId')
    ->desc('Update Twilio provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateTwilioProvider')
    ->label('sdk.description', '/docs/references/messaging/update-twilio-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::patch('/v1/messaging/providers/vonage/:providerId')
    ->desc('Update Vonage provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateVonageProvider')
    ->label('sdk.description', '/docs/references/messaging/update-vonage-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
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

Http::patch('/v1/messaging/providers/fcm/:providerId')
    ->desc('Update FCM provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateFcmProvider')
    ->label('sdk.description', '/docs/references/messaging/update-fcm-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('serviceAccountJSON', null, new JSON(), 'FCM service account JSON.', true)
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


Http::patch('/v1/messaging/providers/apns/:providerId')
    ->desc('Update APNS provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.update')
    ->label('audits.resource', 'provider/{response.$id}')
    ->label('event', 'providers.[providerId].update')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateApnsProvider')
    ->label('sdk.description', '/docs/references/messaging/update-apns-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('authKey', '', new Text(0), 'APNS authentication key.', true)
    ->param('authKeyId', '', new Text(0), 'APNS authentication key ID.', true)
    ->param('teamId', '', new Text(0), 'APNS team ID.', true)
    ->param('bundleId', '', new Text(0), 'APNS bundle ID.', true)
    ->param('sandbox', null, new Boolean(), 'Use APNS sandbox environment.', true)
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

Http::delete('/v1/messaging/providers/:providerId')
    ->desc('Delete provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'provider.delete')
    ->label('audits.resource', 'provider/{request.$providerId}')
    ->label('event', 'providers.[providerId].delete')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'deleteProvider')
    ->label('sdk.description', '/docs/references/messaging/delete-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
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

Http::post('/v1/messaging/topics')
    ->desc('Create topic')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'topic.create')
    ->label('audits.resource', 'topic/{response.$id}')
    ->label('event', 'topics.[topicId].create')
    ->label('scope', 'topics.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createTopic')
    ->label('sdk.description', '/docs/references/messaging/create-topic.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOPIC)
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

Http::get('/v1/messaging/topics')
    ->desc('List topics')
    ->groups(['api', 'messaging'])
    ->label('scope', 'topics.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listTopics')
    ->label('sdk.description', '/docs/references/messaging/list-topics.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOPIC_LIST)
    ->param('queries', [], new Topics(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Topics::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->inject('authorization')
    ->action(function (array $queries, string $search, Database $dbForProject, Response $response, Authorization $authorization) {
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
            $topicId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Topic '{$topicId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument[0]);
        }

        $response->dynamic(new Document([
            'topics' => $dbForProject->find('topics', $queries),
            'total' => $dbForProject->count('topics', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_TOPIC_LIST);
    });

Http::get('/v1/messaging/topics/:topicId/logs')
    ->desc('List topic logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'topics.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listTopicLogs')
    ->label('sdk.description', '/docs/references/messaging/list-topic-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('topicId', '', new UID(), 'Topic ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('authorization')
    ->action(function (string $topicId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb, Authorization $authorization) {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject, $authorization);
        $resource = 'topic/' . $topicId;
        $logs = $audit->getLogsByResource($resource, $limit, $offset);

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
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

Http::get('/v1/messaging/topics/:topicId')
    ->desc('Get topic')
    ->groups(['api', 'messaging'])
    ->label('scope', 'topics.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'getTopic')
    ->label('sdk.description', '/docs/references/messaging/get-topic.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOPIC)
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

Http::patch('/v1/messaging/topics/:topicId')
    ->desc('Update topic')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'topic.update')
    ->label('audits.resource', 'topic/{response.$id}')
    ->label('event', 'topics.[topicId].update')
    ->label('scope', 'topics.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateTopic')
    ->label('sdk.description', '/docs/references/messaging/update-topic.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOPIC)
    ->param('topicId', '', new UID(), 'Topic ID.')
    ->param('name', null, new Text(128), 'Topic Name.', true)
    ->param('subscribe', null, new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of role strings with subscribe permission. By default all users are granted with any subscribe permission. [learn more about roles](https://appwrite.io/docs/permissions#permission-roles). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
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

Http::delete('/v1/messaging/topics/:topicId')
    ->desc('Delete topic')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'topic.delete')
    ->label('audits.resource', 'topic/{request.$topicId}')
    ->label('event', 'topics.[topicId].delete')
    ->label('scope', 'topics.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'deleteTopic')
    ->label('sdk.description', '/docs/references/messaging/delete-topic.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
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

Http::post('/v1/messaging/topics/:topicId/subscribers')
    ->desc('Create subscriber')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'subscriber.create')
    ->label('audits.resource', 'subscriber/{response.$id}')
    ->label('event', 'topics.[topicId].subscribers.[subscriberId].create')
    ->label('scope', 'subscribers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_JWT, APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createSubscriber')
    ->label('sdk.description', '/docs/references/messaging/create-subscriber.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBSCRIBER)
    ->param('subscriberId', '', new CustomId(), 'Subscriber ID. Choose a custom Subscriber ID or a new Subscriber ID.')
    ->param('topicId', '', new UID(), 'Topic ID. The topic ID to subscribe to.')
    ->param('targetId', '', new UID(), 'Target ID. The target ID to link to the specified Topic ID.')
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->inject('authorization')
    ->action(function (string $subscriberId, string $topicId, string $targetId, Event $queueForEvents, Database $dbForProject, Response $response, Authorization $authorization) {
        $subscriberId = $subscriberId == 'unique()' ? ID::unique() : $subscriberId;

        $topic = $authorization->skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $validator = new Authorization();

        if (!$validator->isValid(new Input('subscribe', $topic->getAttribute('subscribe')))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $validator->getDescription());
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
            'topicInternalId' => $topic->getInternalId(),
            'targetId' => $targetId,
            'targetInternalId' => $target->getInternalId(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
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

Http::get('/v1/messaging/topics/:topicId/subscribers')
    ->desc('List subscribers')
    ->groups(['api', 'messaging'])
    ->label('scope', 'subscribers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listSubscribers')
    ->label('sdk.description', '/docs/references/messaging/list-subscribers.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBSCRIBER_LIST)
    ->param('topicId', '', new UID(), 'Topic ID. The topic ID subscribed to.')
    ->param('queries', [], new Subscribers(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Providers::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->inject('authorization')
    ->action(function (string $topicId, array $queries, string $search, Database $dbForProject, Response $response, Authorization $authorization) {
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

        $queries[] = Query::equal('topicInternalId', [$topic->getInternalId()]);

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);

        if ($cursor) {
            $subscriberId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('subscribers', $subscriberId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Subscriber '{$subscriberId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $subscribers = $dbForProject->find('subscribers', $queries);

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
                'total' => $dbForProject->count('subscribers', $queries, APP_LIMIT_COUNT),
            ]), Response::MODEL_SUBSCRIBER_LIST);
    });

Http::get('/v1/messaging/subscribers/:subscriberId/logs')
    ->desc('List subscriber logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'subscribers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listSubscriberLogs')
    ->label('sdk.description', '/docs/references/messaging/list-subscriber-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('subscriberId', '', new UID(), 'Subscriber ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('authorization')
    ->action(function (string $subscriberId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb, Authorization $authorization) {
        $subscriber = $dbForProject->getDocument('subscribers', $subscriberId);

        if ($subscriber->isEmpty()) {
            throw new Exception(Exception::SUBSCRIBER_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject, $authorization);
        $resource = 'subscriber/' . $subscriberId;
        $logs = $audit->getLogsByResource($resource, $limit, $offset);

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
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

Http::get('/v1/messaging/topics/:topicId/subscribers/:subscriberId')
    ->desc('Get subscriber')
    ->groups(['api', 'messaging'])
    ->label('scope', 'subscribers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'getSubscriber')
    ->label('sdk.description', '/docs/references/messaging/get-subscriber.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBSCRIBER)
    ->param('topicId', '', new UID(), 'Topic ID. The topic ID subscribed to.')
    ->param('subscriberId', '', new UID(), 'Subscriber ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->inject('authorization')
    ->action(function (string $topicId, string $subscriberId, Database $dbForProject, Response $response, Authorization $authorization) {
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

Http::delete('/v1/messaging/topics/:topicId/subscribers/:subscriberId')
    ->desc('Delete subscriber')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'subscriber.delete')
    ->label('audits.resource', 'subscriber/{request.$subscriberId}')
    ->label('event', 'topics.[topicId].subscribers.[subscriberId].delete')
    ->label('scope', 'subscribers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_JWT, APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'deleteSubscriber')
    ->label('sdk.description', '/docs/references/messaging/delete-subscriber.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('topicId', '', new UID(), 'Topic ID. The topic ID subscribed to.')
    ->param('subscriberId', '', new UID(), 'Subscriber ID.')
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->inject('authorization')
    ->action(function (string $topicId, string $subscriberId, Event $queueForEvents, Database $dbForProject, Response $response, Authorization $authorization) {
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

Http::post('/v1/messaging/messages/email')
    ->desc('Create email')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.create')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].create')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createEmail')
    ->label('sdk.description', '/docs/references/messaging/create-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('subject', '', new Text(998), 'Email Subject.')
    ->param('content', '', new Text(64230), 'Email Content.')
    ->param('topics', [], new ArrayList(new UID()), 'List of Topic IDs.', true)
    ->param('users', [], new ArrayList(new UID()), 'List of User IDs.', true)
    ->param('targets', [], new ArrayList(new UID()), 'List of Targets IDs.', true)
    ->param('cc', [], new ArrayList(new UID()), 'Array of target IDs to be added as CC.', true)
    ->param('bcc', [], new ArrayList(new UID()), 'Array of target IDs to be added as BCC.', true)
    ->param('attachments', [], new ArrayList(new CompoundUID()), 'Array of compound bucket IDs to file IDs to be attached to the email.', true)
    ->param('draft', false, new Boolean(), 'Is message a draft', true)
    ->param('html', false, new Boolean(), 'Is content of type HTML', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, string $subject, string $content, array $topics, array $users, array $targets, array $cc, array $bcc, array $attachments, bool $draft, bool $html, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForConsole, Document $project, Messaging $queueForMessaging, Response $response) {
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

                $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);

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
                $schedule = $dbForConsole->createDocument('schedules', new Document([
                    'region' => System::getEnv('_APP_REGION', 'default'),
                    'resourceType' => 'message',
                    'resourceId' => $message->getId(),
                    'resourceInternalId' => $message->getInternalId(),
                    'resourceUpdatedAt' => DateTime::now(),
                    'projectId' => $project->getId(),
                    'schedule'  => $scheduledAt,
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

Http::post('/v1/messaging/messages/sms')
    ->desc('Create SMS')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.create')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].create')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createSms')
    ->label('sdk.description', '/docs/references/messaging/create-sms.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('content', '', new Text(64230), 'SMS Content.')
    ->param('topics', [], new ArrayList(new UID()), 'List of Topic IDs.', true)
    ->param('users', [], new ArrayList(new UID()), 'List of User IDs.', true)
    ->param('targets', [], new ArrayList(new UID()), 'List of Targets IDs.', true)
    ->param('draft', false, new Boolean(), 'Is message a draft', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, string $content, array $topics, array $users, array $targets, bool $draft, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForConsole, Document $project, Messaging $queueForMessaging, Response $response) {
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
                $schedule = $dbForConsole->createDocument('schedules', new Document([
                    'region' => System::getEnv('_APP_REGION', 'default'),
                    'resourceType' => 'message',
                    'resourceId' => $message->getId(),
                    'resourceInternalId' => $message->getInternalId(),
                    'resourceUpdatedAt' => DateTime::now(),
                    'projectId' => $project->getId(),
                    'schedule'  => $scheduledAt,
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

Http::post('/v1/messaging/messages/push')
    ->desc('Create push notification')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.create')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].create')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createPush')
    ->label('sdk.description', '/docs/references/messaging/create-push.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('title', '', new Text(256), 'Title for push notification.')
    ->param('body', '', new Text(64230), 'Body for push notification.')
    ->param('topics', [], new ArrayList(new UID()), 'List of Topic IDs.', true)
    ->param('users', [], new ArrayList(new UID()), 'List of User IDs.', true)
    ->param('targets', [], new ArrayList(new UID()), 'List of Targets IDs.', true)
    ->param('data', null, new JSON(), 'Additional Data for push notification.', true)
    ->param('action', '', new Text(256), 'Action for push notification.', true)
    ->param('image', '', new CompoundUID(), 'Image for push notification. Must be a compound bucket ID to file ID of a jpeg, png, or bmp image in Appwrite Storage.', true)
    ->param('icon', '', new Text(256), 'Icon for push notification. Available only for Android and Web Platform.', true)
    ->param('sound', '', new Text(256), 'Sound for push notification. Available only for Android and IOS Platform.', true)
    ->param('color', '', new Text(256), 'Color for push notification. Available only for Android Platform.', true)
    ->param('tag', '', new Text(256), 'Tag for push notification. Available only for Android Platform.', true)
    ->param('badge', '', new Text(256), 'Badge for push notification. Available only for IOS Platform.', true)
    ->param('draft', false, new Boolean(), 'Is message a draft', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, string $title, string $body, array $topics, array $users, array $targets, ?array $data, string $action, string $image, string $icon, string $sound, string $color, string $tag, string $badge, bool $draft, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForConsole, Document $project, Messaging $queueForMessaging, Response $response) {
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

            $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
            if ($file->isEmpty()) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            if (!\in_array($file->getAttribute('mimeType'), ['image/png', 'image/jpeg'])) {
                throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED);
            }

            $host = System::getEnv('_APP_DOMAIN', 'localhost');
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';

            $scheduleTime = $currentScheduledAt ?? $scheduledAt;
            if (!\is_null($scheduleTime)) {
                $expiry = (new \DateTime($scheduleTime))->add(new \DateInterval('P15D'))->format('U');
            } else {
                $expiry = (new \DateTime())->add(new \DateInterval('P15D'))->format('U');
            }

            $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'));

            $jwt = $encoder->encode([
                'iat' => \time(),
                'exp' => $expiry,
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'projectId' => $project->getId(),
            ]);

            $image = [
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'url' => "{$protocol}://{$host}/v1/storage/buckets/{$bucket->getId()}/files/{$file->getId()}/push?project={$project->getId()}&jwt={$jwt}",
            ];
        }

        $pushData = [];

        $keys = ['title', 'body', 'data', 'action', 'image', 'icon', 'sound', 'color', 'tag', 'badge'];

        foreach ($keys as $key) {
            if (!empty($$key)) {
                $pushData[$key] = $$key;
            }
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
                $schedule = $dbForConsole->createDocument('schedules', new Document([
                    'region' => System::getEnv('_APP_REGION', 'default'),
                    'resourceType' => 'message',
                    'resourceId' => $message->getId(),
                    'resourceInternalId' => $message->getInternalId(),
                    'resourceUpdatedAt' => DateTime::now(),
                    'projectId' => $project->getId(),
                    'schedule'  => $scheduledAt,
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

Http::get('/v1/messaging/messages')
    ->desc('List messages')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listMessages')
    ->label('sdk.description', '/docs/references/messaging/list-messages.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE_LIST)
    ->param('queries', [], new Messages(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Messages::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->inject('authorization')
    ->action(function (array $queries, string $search, Database $dbForProject, Response $response, Authorization $authorization) {
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
            $messageId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('messages', $messageId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Message '{$messageId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $response->dynamic(new Document([
            'messages' => $dbForProject->find('messages', $queries),
            'total' => $dbForProject->count('messages', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_MESSAGE_LIST);
    });

Http::get('/v1/messaging/messages/:messageId/logs')
    ->desc('List message logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listMessageLogs')
    ->label('sdk.description', '/docs/references/messaging/list-message-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('authorization')
    ->action(function (string $messageId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb, Authorization $authorization) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject, $authorization);
        $resource = 'message/' . $messageId;
        $logs = $audit->getLogsByResource($resource, $limit, $offset);

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
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

Http::get('/v1/messaging/messages/:messageId/targets')
    ->desc('List message targets')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listTargets')
    ->label('sdk.description', '/docs/references/messaging/list-message-targets.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TARGET_LIST)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('queries', [], new Targets(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Targets::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $messageId, array $queries, Response $response, Database $dbForProject) {
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
            $targetId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('targets', $targetId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Target '{$targetId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $response->dynamic(new Document([
            'targets' => $dbForProject->find('targets', $queries),
            'total' => $dbForProject->count('targets', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_TARGET_LIST);
    });

Http::get('/v1/messaging/messages/:messageId')
    ->desc('Get message')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'getMessage')
    ->label('sdk.description', '/docs/references/messaging/get-message.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
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

Http::patch('/v1/messaging/messages/email/:messageId')
    ->desc('Update email')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.update')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].update')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/messaging/update-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('topics', null, new ArrayList(new UID()), 'List of Topic IDs.', true)
    ->param('users', null, new ArrayList(new UID()), 'List of User IDs.', true)
    ->param('targets', null, new ArrayList(new UID()), 'List of Targets IDs.', true)
    ->param('subject', null, new Text(998), 'Email Subject.', true)
    ->param('content', null, new Text(64230), 'Email Content.', true)
    ->param('draft', null, new Boolean(), 'Is message a draft', true)
    ->param('html', null, new Boolean(), 'Is content of type HTML', true)
    ->param('cc', null, new ArrayList(new UID()), 'Array of target IDs to be added as CC.', true)
    ->param('bcc', null, new ArrayList(new UID()), 'Array of target IDs to be added as BCC.', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, ?array $topics, ?array $users, ?array $targets, ?string $subject, ?string $content, ?bool $draft, ?bool $html, ?array $cc, ?array $bcc, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForConsole, Document $project, Messaging $queueForMessaging, Response $response) {
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
            $schedule = $dbForConsole->createDocument('schedules', new Document([
                'region' => System::getEnv('_APP_REGION', 'default'),
                'resourceType' => 'message',
                'resourceId' => $message->getId(),
                'resourceInternalId' => $message->getInternalId(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'schedule' => $scheduledAt,
                'active' => $status === MessageStatus::SCHEDULED,
            ]));

            $message->setAttribute('scheduleId', $schedule->getId());
        }

        if (!\is_null($currentScheduledAt)) {
            $schedule = $dbForConsole->getDocument('schedules', $message->getAttribute('scheduleId'));
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

            $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule);
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

Http::patch('/v1/messaging/messages/sms/:messageId')
    ->desc('Update SMS')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.update')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].update')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateSms')
    ->label('sdk.description', '/docs/references/messaging/update-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('topics', null, new ArrayList(new UID()), 'List of Topic IDs.', true)
    ->param('users', null, new ArrayList(new UID()), 'List of User IDs.', true)
    ->param('targets', null, new ArrayList(new UID()), 'List of Targets IDs.', true)
    ->param('content', null, new Text(64230), 'Email Content.', true)
    ->param('draft', null, new Boolean(), 'Is message a draft', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, ?array $topics, ?array $users, ?array $targets, ?string $content, ?bool $draft, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForConsole, Document $project, Messaging $queueForMessaging, Response $response) {
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
            $schedule = $dbForConsole->createDocument('schedules', new Document([
                'region' => System::getEnv('_APP_REGION', 'default'),
                'resourceType' => 'message',
                'resourceId' => $message->getId(),
                'resourceInternalId' => $message->getInternalId(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'schedule' => $scheduledAt,
                'active' => $status === MessageStatus::SCHEDULED,
            ]));

            $message->setAttribute('scheduleId', $schedule->getId());
        }

        if (!\is_null($currentScheduledAt)) {
            $schedule = $dbForConsole->getDocument('schedules', $message->getAttribute('scheduleId'));
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

            $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule);
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

Http::patch('/v1/messaging/messages/push/:messageId')
    ->desc('Update push notification')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.update')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].update')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updatePush')
    ->label('sdk.description', '/docs/references/messaging/update-push.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('topics', null, new ArrayList(new UID()), 'List of Topic IDs.', true)
    ->param('users', null, new ArrayList(new UID()), 'List of User IDs.', true)
    ->param('targets', null, new ArrayList(new UID()), 'List of Targets IDs.', true)
    ->param('title', null, new Text(256), 'Title for push notification.', true)
    ->param('body', null, new Text(64230), 'Body for push notification.', true)
    ->param('data', null, new JSON(), 'Additional Data for push notification.', true)
    ->param('action', null, new Text(256), 'Action for push notification.', true)
    ->param('image', null, new CompoundUID(), 'Image for push notification. Must be a compound bucket ID to file ID of a jpeg, png, or bmp image in Appwrite Storage.', true)
    ->param('icon', null, new Text(256), 'Icon for push notification. Available only for Android and Web platforms.', true)
    ->param('sound', null, new Text(256), 'Sound for push notification. Available only for Android and iOS platforms.', true)
    ->param('color', null, new Text(256), 'Color for push notification. Available only for Android platforms.', true)
    ->param('tag', null, new Text(256), 'Tag for push notification. Available only for Android platforms.', true)
    ->param('badge', null, new Integer(), 'Badge for push notification. Available only for iOS platforms.', true)
    ->param('draft', null, new Boolean(), 'Is message a draft', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, ?array $topics, ?array $users, ?array $targets, ?string $title, ?string $body, ?array $data, ?string $action, ?string $image, ?string $icon, ?string $sound, ?string $color, ?string $tag, ?int $badge, ?bool $draft, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Database $dbForConsole, Document $project, Messaging $queueForMessaging, Response $response) {
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
            $schedule = $dbForConsole->createDocument('schedules', new Document([
                'region' => System::getEnv('_APP_REGION', 'default'),
                'resourceType' => 'message',
                'resourceId' => $message->getId(),
                'resourceInternalId' => $message->getInternalId(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'schedule' => $scheduledAt,
                'active' => $status === MessageStatus::SCHEDULED,
            ]));

            $message->setAttribute('scheduleId', $schedule->getId());
        }

        if (!\is_null($currentScheduledAt)) {
            $schedule = $dbForConsole->getDocument('schedules', $message->getAttribute('scheduleId'));
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

            $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule);
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

        if (!\is_null($image)) {
            [$bucketId, $fileId] = CompoundUID::parse($image);

            $bucket = $dbForProject->getDocument('buckets', $bucketId);
            if ($bucket->isEmpty()) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
            if ($file->isEmpty()) {
                throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
            }

            if (!\in_array($file->getAttribute('mimeType'), ['image/png', 'image/jpeg'])) {
                throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED);
            }

            $host = System::getEnv('_APP_DOMAIN', 'localhost');
            $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';

            $scheduleTime = $currentScheduledAt ?? $scheduledAt;
            if (!\is_null($scheduleTime)) {
                $expiry = (new \DateTime($scheduleTime))->add(new \DateInterval('P15D'))->format('U');
            } else {
                $expiry = (new \DateTime())->add(new \DateInterval('P15D'))->format('U');
            }

            $encoder = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'));

            $jwt = $encoder->encode([
                'iat' => \time(),
                'exp' => $expiry,
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'projectId' => $project->getId(),
            ]);

            $pushData['image'] = [
                'bucketId' => $bucket->getId(),
                'fileId' => $file->getId(),
                'url' => "{$protocol}://{$host}/v1/storage/buckets/{$bucket->getId()}/files/{$file->getId()}/push?project={$project->getId()}&jwt={$jwt}"
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

Http::delete('/v1/messaging/messages/:messageId')
    ->desc('Delete message')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.delete')
    ->label('audits.resource', 'message/{request.route.messageId}')
    ->label('event', 'messages.[messageId].delete')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/messaging/delete-message.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('response')
    ->action(function (string $messageId, Database $dbForProject, Database $dbForConsole, Response $response) {
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
                        $dbForConsole->deleteDocument('schedules', $scheduleId);
                    } catch (Exception) {
                        // Ignore
                    }
                }
                break;
            default:
                break;
        }

        $dbForProject->deleteDocument('messages', $message->getId());

        $response->noContent();
    });
