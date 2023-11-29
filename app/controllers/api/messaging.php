<?php

use Appwrite\Auth\Validator\Phone;
use Appwrite\Detector\Detector;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Messaging;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Email;
use Appwrite\Permission;
use Appwrite\Role;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Messages;
use Appwrite\Utopia\Database\Validator\Queries\Providers;
use Appwrite\Utopia\Database\Validator\Queries\Subscribers;
use Appwrite\Utopia\Database\Validator\Queries\Topics;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;
use MaxMind\Db\Reader;
use Utopia\Validator\WhiteList;

use function Swoole\Coroutine\batch;

App::post('/v1/messaging/providers/mailgun')
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
    ->param('from', '', new Email(), 'Sender email address.', true)
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.', true)
    ->param('domain', '', new Text(0), 'Mailgun Domain.', true)
    ->param('isEuRegion', null, new Boolean(), 'Set as EU region.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $from, string $apiKey, string $domain, ?bool $isEuRegion, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $options = [];

        if (!empty($from)) {
            $options ['from'] = $from;
        }

        $credentials = [];

        if ($isEuRegion === true || $isEuRegion === false) {
            $credentials['isEuRegion'] = $isEuRegion;
        }

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (!empty($domain)) {
            $credentials['domain'] = $domain;
        }

        if (
            $enabled === true &&
            \array_key_exists('isEuRegion', $credentials) &&
            \array_key_exists('apiKey', $credentials) &&
            \array_key_exists('domain', $credentials) &&
            \array_key_exists('from', $options)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createSendgridProvider')
    ->label('sdk.description', '/docs/references/messaging/create-sengrid-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('from', '', new Email(), 'Sender email address.', true)
    ->param('apiKey', '', new Text(0), 'Sendgrid API key.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $from, string $apiKey, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $options = [];

        if (!empty($from)) {
            $options ['from'] = $from;
        }

        $credentials = [];

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (
            $enabled === true
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

App::post('/v1/messaging/providers/msg91')
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
            $options ['from'] = $from;
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

App::post('/v1/messaging/providers/telesign')
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
    ->param('username', '', new Text(0), 'Telesign username.', true)
    ->param('password', '', new Text(0), 'Telesign password.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $from, string $username, string $password, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $options = [];

        if (!empty($from)) {
            $options ['from'] = $from;
        }

        $credentials = [];

        if (!empty($username)) {
            $credentials['username'] = $username;
        }

        if (!empty($password)) {
            $credentials['password'] = $password;
        }

        if (
            $enabled === true
            && \array_key_exists('username', $credentials)
            && \array_key_exists('password', $credentials)
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
            $options ['from'] = $from;
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
            $options ['from'] = $from;
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
            'options' => $from,
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
            $options ['from'] = $from;
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
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createFcmProvider')
    ->label('sdk.description', '/docs/references/messaging/create-fcm-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('serverKey', '', new Text(0), 'FCM server key.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $serverKey, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $credentials = [];

        if (!empty($serverKey)) {
            $credentials['serverKey'] = $serverKey;
        }

        if ($enabled === true && \array_key_exists('serverKey', $credentials)) {
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
    ->param('endpoint', '', new Text(0), 'APNS endpoint.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $authKey, string $authKeyId, string $teamId, string $bundleId, string $endpoint, ?bool $enabled, Event $queueForEvents, Database $dbForProject, Response $response) {
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

        if (!empty($endpoint)) {
            $credentials['endpoint'] = $endpoint;
        }

        if (
            $enabled === true
            && \array_key_exists('authKey', $credentials)
            && \array_key_exists('authKeyId', $credentials)
            && \array_key_exists('teamId', $credentials)
            && \array_key_exists('bundleId', $credentials)
            && \array_key_exists('endpoint', $credentials)
        ) {
            $enabled = true;
        } else {
            $enabled = false;
        }

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'apns',
            'type' => MESSAGE_TYPE_PUSH,
            'enabled' => $enabled,
            'credentials' => $credentials,
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
    ->action(function (array $queries, string $search, Database $dbForProject, Response $response) {
        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        $cursor = reset($cursor);

        if ($cursor) {
            $providerId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->getDocument('providers', $providerId));

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

App::get('/v1/messaging/providers/:providerId/logs')
    ->desc('List provider logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'providers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listProviderLogs')
    ->label('sdk.description', '/docs/references/messaging/providers/get-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $providerId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);
        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject);
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

App::get('/v1/messaging/providers/:providerId')
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

App::patch('/v1/messaging/providers/mailgun/:providerId')
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
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('isEuRegion', null, new Boolean(), 'Set as EU region.', true)
    ->param('from', '', new Email(), 'Sender email address.', true)
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.', true)
    ->param('domain', '', new Text(0), 'Mailgun Domain.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, ?bool $isEuRegion, string $from, string $apiKey, string $domain, Event $queueForEvents, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'mailgun') {
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

        if ($isEuRegion === true || $isEuRegion === false) {
            $credentials['isEuRegion'] = $isEuRegion;
        }

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (!empty($domain)) {
            $credentials['domain'] = $domain;
        }

        $provider->setAttribute('credentials', $credentials);

        if ($enabled === true || $enabled === false) {
            if (
                $enabled === true &&
                \array_key_exists('isEuRegion', $credentials) &&
                \array_key_exists('apiKey', $credentials) &&
                \array_key_exists('domain', $credentials) &&
                \array_key_exists('from', $provider->getAttribute('options'))
            ) {
                $enabled = true;
            } else {
                $enabled = false;
            }
            $provider->setAttribute('enabled', $enabled);
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
    ->param('from', '', new Email(), 'Sender email address.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $apiKey, string $from, Event $queueForEvents, Database $dbForProject, Response $response) {
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

        if (!empty($from)) {
            $provider->setAttribute('options', [
                'from' => $from,
            ]);
        }

        if (!empty($apiKey)) {
            $provider->setAttribute('credentials', [
                'apiKey' => $apiKey,
            ]);
        }

        if ($enabled === true || $enabled === false) {
            if (
                $enabled === true
                && \array_key_exists('apiKey', $provider->getAttribute('credentials'))
                && \array_key_exists('from', $provider->getAttribute('options'))
            ) {
                $enabled = true;
            } else {
                $enabled = false;
            }
            $provider->setAttribute('enabled', $enabled);
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

        if ($enabled === true || $enabled === false) {
            if (
                $enabled === true
                && \array_key_exists('senderId', $credentials)
                && \array_key_exists('authKey', $credentials)
                && \array_key_exists('from', $provider->getAttribute('options'))
            ) {
                $enabled = true;
            } else {
                $enabled = false;
            }
            $provider->setAttribute('enabled', $enabled);
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
    ->param('username', '', new Text(0), 'Telesign username.', true)
    ->param('password', '', new Text(0), 'Telesign password.', true)
    ->param('from', '', new Text(256), 'Sender number.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $username, string $password, string $from, Event $queueForEvents, Database $dbForProject, Response $response) {
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

        if (!empty($username)) {
            $credentials['username'] = $username;
        }

        if (!empty($password)) {
            $credentials['password'] = $password;
        }

        $provider->setAttribute('credentials', $credentials);

        if ($enabled === true || $enabled === false) {
            if (
                $enabled === true
                && \array_key_exists('username', $credentials)
                && \array_key_exists('password', $credentials)
                && \array_key_exists('from', $provider->getAttribute('options'))
            ) {
                $enabled = true;
            } else {
                $enabled = false;
            }

            $provider->setAttribute('enabled', $enabled);
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

        if ($enabled === true || $enabled === false) {
            if (
                $enabled === true
                && \array_key_exists('username', $credentials)
                && \array_key_exists('apiKey', $credentials)
                && \array_key_exists('from', $provider->getAttribute('options'))
            ) {
                $enabled = true;
            } else {
                $enabled = false;
            }

            $provider->setAttribute('enabled', $enabled);
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
    ->param('accountSid', null, new Text(0), 'Twilio account secret ID.', true)
    ->param('authToken', null, new Text(0), 'Twilio authentication token.', true)
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

        if ($enabled === true || $enabled === false) {
            if (
                $enabled === true
                && \array_key_exists('accountSid', $credentials)
                && \array_key_exists('authToken', $credentials)
                && \array_key_exists('from', $provider->getAttribute('options'))
            ) {
                $enabled = true;
            } else {
                $enabled = false;
            }

            $provider->setAttribute('enabled', $enabled);
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

        if ($enabled === true || $enabled === false) {
            if (
                $enabled === true
                && \array_key_exists('apiKey', $credentials)
                && \array_key_exists('apiSecret', $credentials)
                && \array_key_exists('from', $provider->getAttribute('options'))
            ) {
                $enabled = true;
            } else {
                $enabled = false;
            }

            $provider->setAttribute('enabled', $enabled);
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
    ->param('serverKey', '', new Text(0), 'FCM Server Key.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $serverKey, Event $queueForEvents, Database $dbForProject, Response $response) {
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

        if (!empty($serverKey)) {
            $provider->setAttribute('credentials', ['serverKey' => $serverKey]);
        }

        if ($enabled === true || $enabled === false) {
            if ($enabled === true && \array_key_exists('serverKey', $provider->getAttribute('credentials'))) {
                $enabled = true;
            } else {
                $enabled = false;
            }

            $provider->setAttribute('enabled', $enabled);
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
    ->param('endpoint', '', new Text(0), 'APNS endpoint.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $authKey, string $authKeyId, string $teamId, string $bundleId, string $endpoint, Event $queueForEvents, Database $dbForProject, Response $response) {
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

        if (!empty($endpoint)) {
            $credentials['endpoint'] = $endpoint;
        }

        $provider->setAttribute('credentials', $credentials);

        if ($enabled === true || $enabled === false) {
            if (
                $enabled === true
                && \array_key_exists('authKey', $credentials)
                && \array_key_exists('authKeyId', $credentials)
                && \array_key_exists('teamId', $credentials)
                && \array_key_exists('bundleId', $credentials)
                && \array_key_exists('endpoint', $credentials)
            ) {
                $enabled = true;
            } else {
                $enabled = false;
            }

            $provider->setAttribute('enabled', $enabled);
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

App::post('/v1/messaging/topics')
    ->desc('Create a topic.')
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
    ->param('description', '', new Text(2048), 'Topic Description.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $topicId, string $name, string $description, Event $queueForEvents, Database $dbForProject, Response $response) {
        $topicId = $topicId == 'unique()' ? ID::unique() : $topicId;

        $topic = new Document([
            '$id' => $topicId,
            'name' => $name,
        ]);

        if ($description) {
            $topic->setAttribute('description', $description);
        }

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
    ->desc('List topics.')
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
    ->action(function (array $queries, string $search, Database $dbForProject, Response $response) {
        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        $cursor = reset($cursor);

        if ($cursor) {
            $topicId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->getDocument('topics', $topicId));

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

App::get('/v1/messaging/topics/:topicId/logs')
    ->desc('List topic logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'topics.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listTopicLogs')
    ->label('sdk.description', '/docs/references/messaging/topics/get-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('topicId', '', new UID(), 'Topic ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $topicId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);
        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject);
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

App::get('/v1/messaging/topics/:topicId')
    ->desc('Get a topic.')
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

        $topic = $dbForProject->getDocument('topics', $topicId);

        $response
            ->dynamic($topic, Response::MODEL_TOPIC);
    });

App::patch('/v1/messaging/topics/:topicId')
    ->desc('Update a topic.')
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
    ->param('name', '', new Text(128), 'Topic Name.', true)
    ->param('description', '', new Text(2048), 'Topic Description.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $topicId, string $name, string $description, Event $queueForEvents, Database $dbForProject, Response $response) {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        if (!empty($name)) {
            $topic->setAttribute('name', $name);
        }

        if (!empty($description)) {
            $topic->setAttribute('description', $description);
        }

        $topic = $dbForProject->updateDocument('topics', $topicId, $topic);

        $queueForEvents
            ->setParam('topicId', $topic->getId());

        $response
            ->dynamic($topic, Response::MODEL_TOPIC);
    });

App::delete('/v1/messaging/topics/:topicId')
    ->desc('Delete a topic.')
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

App::post('/v1/messaging/topics/:topicId/subscribers')
    ->desc('Create a subscriber.')
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
    ->action(function (string $subscriberId, string $topicId, string $targetId, Event $queueForEvents, Database $dbForProject, Response $response) {
        $subscriberId = $subscriberId == 'unique()' ? ID::unique() : $subscriberId;

        $topic = Authorization::skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $target = Authorization::skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        $user = Authorization::skip(fn () => $dbForProject->getDocument('users', $target->getAttribute('userId')));

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
        ]);

        try {
            $subscriber = $dbForProject->createDocument('subscribers', $subscriber);
            Authorization::skip(fn () => $dbForProject->increaseDocumentAttribute('topics', $topicId, 'total', 1));
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
    ->desc('List subscribers.')
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
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $topicId, array $queries, Database $dbForProject, Response $response) {
        $queries = Query::parseQueries($queries);

        $topic = Authorization::skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        \array_push($queries, Query::equal('topicInternalId', [$topic->getInternalId()]));

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        $cursor = reset($cursor);

        if ($cursor) {
            $subscriberId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->getDocument('subscribers', $subscriberId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Subscriber '{$subscriberId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $subscribers = $dbForProject->find('subscribers', $queries);

        $subscribers = batch(\array_map(function (Document $subscriber) use ($dbForProject) {
            return function () use ($subscriber, $dbForProject) {
                $target = Authorization::skip(fn () => $dbForProject->getDocument('targets', $subscriber->getAttribute('targetId')));
                $user = Authorization::skip(fn () => $dbForProject->getDocument('users', $target->getAttribute('userId')));

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

App::get('/v1/messaging/subscribers/:subscriberId/logs')
    ->desc('List subscriber logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'subscribers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listSubscriberLogs')
    ->label('sdk.description', '/docs/references/messaging/subscribers/get-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('subscriberId', '', new UID(), 'Subscriber ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $subscriberId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $subscriber = $dbForProject->getDocument('subscribers', $subscriberId);

        if ($subscriber->isEmpty()) {
            throw new Exception(Exception::SUBSCRIBER_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);
        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject);
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

App::get('/v1/messaging/topics/:topicId/subscribers/:subscriberId')
    ->desc('Get a subscriber.')
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
    ->action(function (string $topicId, string $subscriberId, Database $dbForProject, Response $response) {
        $topic = Authorization::skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $subscriber = $dbForProject->getDocument('subscribers', $subscriberId);

        if ($subscriber->isEmpty() || $subscriber->getAttribute('topicId') !== $topicId) {
            throw new Exception(Exception::SUBSCRIBER_NOT_FOUND);
        }

        $target = Authorization::skip(fn () => $dbForProject->getDocument('targets', $subscriber->getAttribute('targetId')));
        $user = Authorization::skip(fn () => $dbForProject->getDocument('users', $target->getAttribute('userId')));

        $subscriber
            ->setAttribute('target', $target)
            ->setAttribute('userName', $user->getAttribute('name'));

        $response
            ->dynamic($subscriber, Response::MODEL_SUBSCRIBER);
    });

App::delete('/v1/messaging/topics/:topicId/subscribers/:subscriberId')
    ->desc('Delete a subscriber.')
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
    ->action(function (string $topicId, string $subscriberId, Event $queueForEvents, Database $dbForProject, Response $response) {
        $topic = Authorization::skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $subscriber = $dbForProject->getDocument('subscribers', $subscriberId);

        if ($subscriber->isEmpty() || $subscriber->getAttribute('topicId') !== $topicId) {
            throw new Exception(Exception::SUBSCRIBER_NOT_FOUND);
        }

        $dbForProject->deleteDocument('subscribers', $subscriberId);
        Authorization::skip(fn () => $dbForProject->decreaseDocumentAttribute('topics', $topicId, 'total', 1));

        $queueForEvents
            ->setParam('topicId', $topic->getId())
            ->setParam('subscriberId', $subscriber->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_NOCONTENT)
            ->noContent();
    });

App::post('/v1/messaging/messages/email')
    ->desc('Create an email.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.create')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].create')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createEmailMessage')
    ->label('sdk.description', '/docs/references/messaging/create-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('subject', '', new Text(998), 'Email Subject.')
    ->param('content', '', new Text(64230), 'Email Content.')
    ->param('topics', [], new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Topic IDs.', true)
    ->param('users', [], new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of User IDs.', true)
    ->param('targets', [], new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Targets IDs.', true)
    ->param('description', '', new Text(256), 'Description for message.', true)
    ->param('status', 'processing', new WhiteList(['draft', 'processing']), 'Message Status. Value must be either draft or processing.', true)
    ->param('html', false, new Boolean(), 'Is content of type HTML', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, string $subject, string $content, array $topics, array $users, array $targets, string $description, string $status, bool $html, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Document $project, Messaging $queueForMessaging, Response $response) {
        $messageId = $messageId == 'unique()' ? ID::unique() : $messageId;

        if (\count($topics) === 0 && \count($users) === 0 && \count($targets) === 0) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        $message = $dbForProject->createDocument('messages', new Document([
            '$id' => $messageId,
            'topics' => $topics,
            'users' => $users,
            'targets' => $targets,
            'description' => $description,
            'data' => [
                'subject' => $subject,
                'content' => $content,
                'html' => $html,
            ],
            'status' => $status,
        ]));

        if ($status === 'processing') {
            $queueForMessaging
                ->setMessageId($message->getId())
                ->setProject($project)
                ->trigger();
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::post('/v1/messaging/messages/sms')
    ->desc('Create an SMS.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.create')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].create')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createSMSMessage')
    ->label('sdk.description', '/docs/references/messaging/create-sms.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('content', '', new Text(64230), 'SMS Content.')
    ->param('topics', [], new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Topic IDs.', true)
    ->param('users', [], new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of User IDs.', true)
    ->param('targets', [], new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Targets IDs.', true)
    ->param('description', '', new Text(256), 'Description for Message.', true)
    ->param('status', 'processing', new WhiteList(['draft', 'processing']), 'Message Status. Value must be either draft or processing.', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, string $content, array $topics, array $users, array $targets, string $description, string $status, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Document $project, Messaging $queueForMessaging, Response $response) {
        $messageId = $messageId == 'unique()' ? ID::unique() : $messageId;

        if (\count($topics) === 0 && \count($users) === 0 && \count($targets) === 0) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        $message = $dbForProject->createDocument('messages', new Document([
            '$id' => $messageId,
            'topics' => $topics,
            'users' => $users,
            'targets' => $targets,
            'description' => $description,
            'data' => [
                'content' => $content,
            ],
            'status' => $status,
        ]));

        if ($status === 'processing') {
            $queueForMessaging
                ->setMessageId($message->getId())
                ->setProject($project)
                ->trigger();
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::post('/v1/messaging/messages/push')
    ->desc('Create a push notification.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.create')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].create')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createPushMessage')
    ->label('sdk.description', '/docs/references/messaging/create-push-notification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('title', '', new Text(256), 'Title for push notification.')
    ->param('body', '', new Text(64230), 'Body for push notification.')
    ->param('topics', [], new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Topic IDs.', true)
    ->param('users', [], new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of User IDs.', true)
    ->param('targets', [], new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Targets IDs.', true)
    ->param('description', '', new Text(256), 'Description for Message.', true)
    ->param('data', null, new JSON(), 'Additional Data for push notification.', true)
    ->param('action', '', new Text(256), 'Action for push notification.', true)
    ->param('icon', '', new Text(256), 'Icon for push notification. Available only for Android and Web Platform.', true)
    ->param('sound', '', new Text(256), 'Sound for push notification. Available only for Android and IOS Platform.', true)
    ->param('color', '', new Text(256), 'Color for push notification. Available only for Android Platform.', true)
    ->param('tag', '', new Text(256), 'Tag for push notification. Available only for Android Platform.', true)
    ->param('badge', '', new Text(256), 'Badge for push notification. Available only for IOS Platform.', true)
    ->param('status', 'processing', new WhiteList(['draft', 'processing']), 'Message Status. Value must be either draft or processing.', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, string $title, string $body, array $topics, array $users, array $targets, string $description, ?array $data, string $action, string $icon, string $sound, string $color, string $tag, string $badge, string $status, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Document $project, Messaging $queueForMessaging, Response $response) {
        $messageId = $messageId == 'unique()' ? ID::unique() : $messageId;

        if (\count($topics) === 0 && \count($users) === 0 && \count($targets) === 0) {
            throw new Exception(Exception::MESSAGE_MISSING_TARGET);
        }

        $pushData = [
            'title' => $title,
            'body' => $body,
        ];

        if (!is_null($data)) {
            $pushData['data'] = $data;
        }

        if ($action) {
            $pushData['action'] = $action;
        }

        if ($icon) {
            $pushData['icon'] = $icon;
        }

        if ($sound) {
            $pushData['sound'] = $sound;
        }

        if ($color) {
            $pushData['color'] = $color;
        }

        if ($tag) {
            $pushData['tag'] = $tag;
        }

        if ($badge) {
            $pushData['badge'] = $badge;
        }

        $message = $dbForProject->createDocument('messages', new Document([
            '$id' => $messageId,
            'topics' => $topics,
            'users' => $users,
            'targets' => $targets,
            'description' => $description,
            'scheduledAt' => $scheduledAt,
            'data' => $pushData,
            'status' => $status,
        ]));

        if ($status === 'processing') {
            $queueForMessaging
                ->setMessageId($message->getId())
                ->setProject($project)
                ->trigger();
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
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listMessages')
    ->label('sdk.description', '/docs/references/messaging/list-messages.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE_LIST)
    ->param('queries', [], new Messages(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Providers::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (array $queries, string $search, Database $dbForProject, Response $response) {
        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        $cursor = reset($cursor);

        if ($cursor) {
            $messageId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->getDocument('messages', $messageId));

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

App::get('/v1/messaging/messages/:messageId/logs')
    ->desc('List message logs')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listMessageLogs')
    ->label('sdk.description', '/docs/references/messaging/messages/get-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $messageId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);
        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject);
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

App::get('/v1/messaging/messages/:messageId')
    ->desc('Get a message')
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

App::patch('/v1/messaging/messages/email/:messageId')
    ->desc('Update an email.')
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
    ->param('topics', null, new ArrayList(new Text(Database::LENGTH_KEY)), 'List of Topic IDs.', true)
    ->param('users', null, new ArrayList(new Text(Database::LENGTH_KEY)), 'List of User IDs.', true)
    ->param('targets', null, new ArrayList(new Text(Database::LENGTH_KEY)), 'List of Targets IDs.', true)
    ->param('subject', '', new Text(998), 'Email Subject.', true)
    ->param('description', '', new Text(256), 'Description for Message.', true)
    ->param('content', '', new Text(64230), 'Email Content.', true)
    ->param('status', '', new WhiteList(['draft', 'processing']), 'Message Status. Value must be either draft or processing.', true)
    ->param('html', false, new Boolean(), 'Is content of type HTML', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, ?array $topics, ?array $users, ?array $targets, string $subject, string $description, string $content, string $status, bool $html, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Document $project, Messaging $queueForMessaging, Response $response) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        if ($message->getAttribute('status') === 'sent') {
            throw new Exception(Exception::MESSAGE_ALREADY_SENT);
        }

        if (!is_null($message->getAttribute('scheduledAt')) && $message->getAttribute('scheduledAt') < new \DateTime()) {
            throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
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

        if (!empty($subject)) {
            $data['subject'] = $subject;
        }

        if (!empty($content)) {
            $data['content'] = $content;
        }

        if (!empty($html)) {
            $data['html'] = $html;
        }

        $message->setAttribute('data', $data);

        if (!empty($description)) {
            $message->setAttribute('description', $description);
        }

        if (!empty($status)) {
            $message->setAttribute('status', $status);
        }

        if (!is_null($scheduledAt)) {
            $message->setAttribute('scheduledAt', $scheduledAt);
        }

        $message = $dbForProject->updateDocument('messages', $message->getId(), $message);

        if ($status === 'processing') {
            $queueForMessaging
                ->setMessageId($message->getId())
                ->setProject($project)
                ->trigger();
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::patch('/v1/messaging/messages/sms/:messageId')
    ->desc('Update an SMS.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.update')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].update')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateSMS')
    ->label('sdk.description', '/docs/references/messaging/update-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('topics', null, new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Topic IDs.', true)
    ->param('users', null, new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of User IDs.', true)
    ->param('targets', null, new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Targets IDs.', true)
    ->param('description', '', new Text(256), 'Description for Message.', true)
    ->param('content', '', new Text(64230), 'Email Content.', true)
    ->param('status', '', new WhiteList(['draft', 'processing']), 'Message Status. Value must be either draft or processing.', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, ?array $topics, ?array $users, ?array $targets, string $description, string $content, string $status, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Document $project, Messaging $queueForMessaging, Response $response) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        if ($message->getAttribute('status') === 'sent') {
            throw new Exception(Exception::MESSAGE_ALREADY_SENT);
        }

        if (!is_null($message->getAttribute('scheduledAt')) && $message->getAttribute('scheduledAt') < new \DateTime()) {
            throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
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

        if (!empty($content)) {
            $data['content'] = $content;
        }

        $message->setAttribute('data', $data);

        if (!empty($status)) {
            $message->setAttribute('status', $status);
        }

        if (!empty($description)) {
            $message->setAttribute('description', $description);
        }

        if (!is_null($scheduledAt)) {
            $message->setAttribute('scheduledAt', $scheduledAt);
        }

        $message = $dbForProject->updateDocument('messages', $message->getId(), $message);

        if ($status === 'processing') {
            $queueForMessaging
                ->setMessageId($message->getId())
                ->setProject($project)
                ->trigger();
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::patch('/v1/messaging/messages/push/:messageId')
    ->desc('Update a push notification.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'message.update')
    ->label('audits.resource', 'message/{response.$id}')
    ->label('event', 'messages.[messageId].update')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updatePushNotification')
    ->label('sdk.description', '/docs/references/messaging/update-push-notification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('topics', null, new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Topic IDs.', true)
    ->param('users', null, new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of User IDs.', true)
    ->param('targets', null, new ArrayList(new Text(Database::LENGTH_KEY), 1), 'List of Targets IDs.', true)
    ->param('description', '', new Text(256), 'Description for Message.', true)
    ->param('title', '', new Text(256), 'Title for push notification.', true)
    ->param('body', '', new Text(64230), 'Body for push notification.', true)
    ->param('data', null, new JSON(), 'Additional Data for push notification.', true)
    ->param('action', '', new Text(256), 'Action for push notification.', true)
    ->param('icon', '', new Text(256), 'Icon for push notification. Available only for Android and Web Platform.', true)
    ->param('sound', '', new Text(256), 'Sound for push notification. Available only for Android and IOS Platform.', true)
    ->param('color', '', new Text(256), 'Color for push notification. Available only for Android Platform.', true)
    ->param('tag', '', new Text(256), 'Tag for push notification. Available only for Android Platform.', true)
    ->param('badge', '', new Text(256), 'Badge for push notification. Available only for IOS Platform.', true)    ->param('status', 'processing', new WhiteList(['draft', 'processing']), 'Message Status. Value must be either draft or processing.', true)
    ->param('scheduledAt', null, new DatetimeValidator(requireDateInFuture: true), 'Scheduled delivery time for message in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. DateTime value must be in future.', true)
    ->inject('queueForEvents')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForMessaging')
    ->inject('response')
    ->action(function (string $messageId, ?array $topics, ?array $users, ?array $targets, string $description, string $title, string $body, ?array $data, string $action, string $icon, string $sound, string $color, string $tag, string $badge, string $status, ?string $scheduledAt, Event $queueForEvents, Database $dbForProject, Document $project, Messaging $queueForMessaging, Response $response) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        if ($message->getAttribute('status') === 'sent') {
            throw new Exception(Exception::MESSAGE_ALREADY_SENT);
        }

        if (!is_null($message->getAttribute('scheduledAt')) && $message->getAttribute('scheduledAt') < new \DateTime()) {
            throw new Exception(Exception::MESSAGE_ALREADY_SCHEDULED);
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

        if ($title) {
            $pushData['title'] = $title;
        }

        if ($body) {
            $pushData['body'] = $body;
        }

        if (!is_null($data)) {
            $pushData['data'] = $data;
        }

        if ($action) {
            $pushData['action'] = $action;
        }

        if ($icon) {
            $pushData['icon'] = $icon;
        }

        if ($sound) {
            $pushData['sound'] = $sound;
        }

        if ($color) {
            $pushData['color'] = $color;
        }

        if ($tag) {
            $pushData['tag'] = $tag;
        }

        if ($badge) {
            $pushData['badge'] = $badge;
        }

        $message->setAttribute('data', $pushData);

        if (!empty($status)) {
            $message->setAttribute('status', $status);
        }

        if (!empty($description)) {
            $message->setAttribute('description', $description);
        }

        if (!is_null($scheduledAt)) {
            $message->setAttribute('scheduledAt', $scheduledAt);
        }

        $message = $dbForProject->updateDocument('messages', $message->getId(), $message);

        if ($status === 'processing') {
            $queueForMessaging
                ->setMessageId($message->getId())
                ->setProject($project)
                ->trigger();
        }

        $queueForEvents
            ->setParam('messageId', $message->getId());

        $response
            ->dynamic($message, Response::MODEL_MESSAGE);
    });
