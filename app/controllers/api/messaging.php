<?php

use Appwrite\Event\Messaging;
use Appwrite\Extend\Exception;
use Appwrite\Permission;
use Appwrite\Role;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Providers;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::post('/v1/messaging/providers/mailgun')
    ->desc('Create Mailgun Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
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
    ->param('default', false, new Boolean(), 'Set as default provider.', true)
    ->param('enabled', true, new Boolean(), 'Set as enabled.', true)
    ->param('isEuRegion', false, new Boolean(), 'Set as EU region.', true)
    ->param('from', '', new Text(256), 'Sender Email Address.')
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.')
    ->param('domain', '', new Text(0), 'Mailgun Domain.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, bool $default, bool $enabled, bool $isEuRegion, string $from, string $apiKey, string $domain, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;

        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'mailgun',
            'type' => 'email',
            'default' => $default,
            'enabled' => $enabled,
            'search' => $providerId . ' ' . $name . ' ' . 'mailgun' . ' ' . 'email',
            'credentials' => [
                'apiKey' => $apiKey,
                'domain' => $domain,
                'isEuRegion' => $isEuRegion,
            ],
            'options' => [
                'from' => $from,
            ]
        ]);

        // Check if a default provider exists, if not, set this one as default
        if (
            empty($dbForProject->findOne('providers', [
            Query::equal('default', [true]),
            Query::equal('type', ['email'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS, 'Provider already exists.');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/sendgrid')
    ->desc('Create Sendgrid Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
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
    ->param('default', false, new Boolean(), 'Set as default provider.', true)
    ->param('enabled', true, new Boolean(), 'Set as enabled.', true)
    ->param('apiKey', '', new Text(0), 'Sendgrid API key.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, bool $default, bool $enabled, string $apiKey, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'sendgrid',
            'type' => 'email',
            'default' => $default,
            'enabled' => $enabled,
            'options' => [],
            'search' => $providerId . ' ' . $name . ' ' . 'sendgrid' . ' ' . 'email',
            'credentials' => [
                'apiKey' => $apiKey,
            ],
        ]);

        // Check if a default provider exists, if not, set this one as default
        if (
            empty($dbForProject->findOne('providers', [
            Query::equal('default', [true]),
            Query::equal('type', ['sms'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS, 'Provider already exists.');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/msg91')
    ->desc('Create Msg91 Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createMsg91Provider')
    ->label('sdk.description', '/docs/references/messaging/create-msg91-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('default', false, new Boolean(), 'Set as default provider.', true)
    ->param('enabled', true, new Boolean(), 'Set as enabled.', true)
    ->param('from', '', new Text(256), 'Sender Number.')
    ->param('senderId', '', new Text(0), 'Msg91 Sender ID.')
    ->param('authKey', '', new Text(0), 'Msg91 Auth Key.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, bool $default, bool $enabled, string $from, string $senderId, string $authKey, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'msg91',
            'type' => 'sms',
            'search' => $providerId . ' ' . $name . ' ' . 'msg91' . ' ' . 'sms',
            'default' => $default,
            'enabled' => $enabled,
            'credentials' => [
                'senderId' => $senderId,
                'authKey' => $authKey,
            ],
            'options' => [
                'from' => $from,
            ]
        ]);

        // Check if a default provider exists, if not, set this one as default
        if (
            empty($dbForProject->findOne('providers', [
                Query::equal('default', [true]),
                Query::equal('type', ['sms'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS, 'Provider already exists.');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });
App::post('/v1/messaging/providers/telesign')
    ->desc('Create Telesign Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
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
    ->param('default', false, new Boolean(), 'Set as default provider.', true)
    ->param('enabled', true, new Boolean(), 'Set as enabled.', true)
    ->param('username', '', new Text(0), 'Telesign username.')
    ->param('password', '', new Text(0), 'Telesign password.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, bool $default, bool $enabled, string $username, string $password, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'telesign',
            'type' => 'sms',
            'search' => $providerId . ' ' . $name . ' ' . 'telesign' . ' ' . 'sms',
            'default' => $default,
            'enabled' => $enabled,
            'credentials' => [
                'username' => $username,
                'password' => $password,
            ],
        ]);

        // Check if a default provider exists, if not, set this one as default
        if (
            empty($dbForProject->findOne('providers', [
            Query::equal('default', [true]),
            Query::equal('type', ['sms'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS, 'Provider already exists.');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/textmagic')
    ->desc('Create Textmagic Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
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
    ->param('default', false, new Boolean(), 'Set as default provider.', true)
    ->param('enabled', true, new Boolean(), 'Set as enabled.', true)
    ->param('username', '', new Text(0), 'Textmagic username.')
    ->param('apiKey', '', new Text(0), 'Textmagic apiKey.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, bool $default, bool $enabled, string $username, string $apiKey, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'text-magic',
            'type' => 'sms',
            'search' => $providerId . ' ' . $name . ' ' . 'text-magic' . ' ' . 'sms',
            'default' => $default,
            'enabled' => $enabled,
            'credentials' => [
                'username' => $username,
                'apiKey' => $apiKey,
            ],
        ]);

        // Check if a default provider exists, if not, set this one as default
        if (
            empty($dbForProject->findOne('providers', [
            Query::equal('default', [true]),
            Query::equal('type', ['sms'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS, 'Provider already exists.');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/twilio')
    ->desc('Create Twilio Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
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
    ->param('default', false, new Boolean(), 'Set as default provider.', true)
    ->param('enabled', true, new Boolean(), 'Set as enabled.', true)
    ->param('accountSid', '', new Text(0), 'Twilio account secret ID.')
    ->param('authToken', '', new Text(0), 'Twilio authentication token.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, bool $default, bool $enabled, string $accountSid, string $authToken, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'twilio',
            'type' => 'sms',
            'search' => $providerId . ' ' . $name . ' ' . 'twilio' . ' ' . 'sms',
            'default' => $default,
            'enabled' => $enabled,
            'credentials' => [
                'accountSid' => $accountSid,
                'authToken' => $authToken,
            ],
        ]);

        // Check if a default provider exists, if not, set this one as default
        if (
            empty($dbForProject->findOne('providers', [
            Query::equal('default', [true]),
            Query::equal('type', ['sms'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS, 'Provider already exists.');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/vonage')
    ->desc('Create Vonage Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
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
    ->param('default', false, new Boolean(), 'Set as default provider.', true)
    ->param('enabled', true, new Boolean(), 'Set as enabled.', true)
    ->param('apiKey', '', new Text(0), 'Vonage API key.')
    ->param('apiSecret', '', new Text(0), 'Vonage API secret.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, bool $default, bool $enabled, string $apiKey, string $apiSecret, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'vonage',
            'type' => 'sms',
            'search' => $providerId . ' ' . $name . ' ' . 'vonage' . ' ' . 'sms',
            'default' => $default,
            'enabled' => $enabled,
            'credentials' => [
                'apiKey' => $apiKey,
                'apiSecret' => $apiSecret,
            ],
        ]);

        // Check if a default provider exists, if not, set this one as default
        if (
            empty($dbForProject->findOne('providers', [
            Query::equal('default', [true]),
                Query::equal('type', ['sms'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS, 'Provider already exists.');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/fcm')
    ->desc('Create FCM Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createFCMProvider')
    ->label('sdk.description', '/docs/references/messaging/create-fcm-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('default', false, new Boolean(), 'Set as default provider.', true)
    ->param('enabled', true, new Boolean(), 'Set as enabled.', true)
    ->param('serverKey', '', new Text(0), 'FCM Server Key.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, bool $default, bool $enabled, string $serverKey, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'fcm',
            'type' => 'push',
            'search' => $providerId . ' ' . $name . ' ' . 'fcm' . ' ' . 'push',
            'default' => $default,
            'enabled' => $enabled,
            'credentials' => [
                'serverKey' => $serverKey,
            ],
        ]);

        // Check if a default provider exists, if not, set this one as default
        if (
            empty($dbForProject->findOne('providers', [
            Query::equal('default', [true]),
            Query::equal('type', ['pushq'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS, 'Provider already exists.');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/apns')
    ->desc('Create APNS Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createAPNSProvider')
    ->label('sdk.description', '/docs/references/messaging/create-apns-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('default', false, new Boolean(), 'Set as default provider.', true)
    ->param('enabled', true, new Boolean(), 'Set as enabled.', true)
    ->param('authKey', '', new Text(0), 'APNS authentication key.')
    ->param('authKeyId', '', new Text(0), 'APNS authentication key ID.')
    ->param('teamId', '', new Text(0), 'APNS team ID.')
    ->param('bundleId', '', new Text(0), 'APNS bundle ID.')
    ->param('endpoint', '', new Text(0), 'APNS endpoint.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, bool $default, bool $enabled, string $authKey, string $authKeyId, string $teamId, string $bundleId, string $endpoint, Database $dbForProject, Response $response) {
        $providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
        $provider = new Document([
            '$id' => $providerId,
            'name' => $name,
            'provider' => 'apns',
            'type' => 'push',
            'search' => $providerId . ' ' . $name . ' ' . 'apns' . ' ' . 'push',
            'default' => $default,
            'enabled' => $enabled,
            'credentials' => [
                'authKey' => $authKey,
                'authKeyId' => $authKeyId,
                'teamId' => $teamId,
                'bundleId' => $bundleId,
                'endpoint' => $endpoint,
            ],
        ]);

        // Check if a default provider exists, if not, set this one as default
        if (
            empty($dbForProject->findOne('providers', [
            Query::equal('default', [true]),
            Query::equal('type', ['push'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS, 'Provider already exists.');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::get('/v1/messaging/providers')
    ->desc('List Providers')
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
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (array $queries, Database $dbForProject, Response $response) {
        $queries = Query::parseQueries($queries);

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        $cursor = reset($cursor);

        if ($cursor) {
            $providerId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->findOne('providers', [
                Query::equal('$id', [$providerId]),
            ]));

            if (empty($cursorDocument) || $cursorDocument[0]->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Provider '{$providerId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument[0]);
        }

        $filterQueries = Query::groupByType($queries)['filters'];
        $response->dynamic(new Document([
            'total' => $dbForProject->count('providers', $filterQueries, APP_LIMIT_COUNT),
            'providers' => $dbForProject->find('providers', $queries),
        ]), Response::MODEL_PROVIDER_LIST);
    });

App::get('/v1/messaging/providers/:id')
    ->desc('Get Provider')
    ->groups(['api', 'messaging'])
    ->label('scope', 'providers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'getProvider')
    ->label('sdk.description', '/docs/references/messaging/get-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $response->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/mailgun/:id')
    ->desc('Update Mailgun Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateMailgunProvider')
    ->label('sdk.description', '/docs/references/messaging/update-mailgun-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('isEuRegion', null, new Boolean(), 'Set as eu region.', true)
    ->param('from', '', new Text(256), 'Sender Email Address.', true)
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.', true)
    ->param('domain', '', new Text(0), 'Mailgun Domain.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, ?bool $enabled, ?bool $isEuRegion, string $from, string $apiKey, string $domain, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'mailgun') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE . $providerAttr);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
            $provider->setAttribute('search', $provider->getId() . ' ' . $name . ' ' . 'mailgun' . ' ' . 'email');
        }

        if (!empty($from)) {
            $provider->setAttribute('options', [
                'from' => $from,
            ]);
        }

        if ($enabled === true || $enabled === false) {
            $provider->setAttribute('enabled', $enabled);
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

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/sendgrid/:id')
    ->desc('Update Sendgrid Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateSendgridProvider')
    ->label('sdk.description', '/docs/references/messaging/update-sendgrid-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('apiKey', '', new Text(0), 'Sendgrid API key.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, ?bool $enabled, string $apiKey, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'sendgrid') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE . $providerAttr);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
            $provider->setAttribute('search', $provider->getId() . ' ' . $name . ' ' . 'sendgrid' . ' ' . 'email');
        }

        if ($enabled === true || $enabled === false) {
            $provider->setAttribute('enabled', $enabled);
        }

        if (!empty($apiKey)) {
            $provider->setAttribute('credentials', [
                'apiKey' => $apiKey,
            ]);
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/msg91/:id')
    ->desc('Update Msg91 Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateMsg91Provider')
    ->label('sdk.description', '/docs/references/messaging/update-msg91-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('senderId', '', new Text(0), 'Msg91 Sender ID.', true)
    ->param('authKey', '', new Text(0), 'Msg91 Auth Key.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, ?bool $enabled, string $senderId, string $authKey, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'msg91') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE . $providerAttr);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
            $provider->setAttribute('search', $provider->getId() . ' ' . $name . ' ' . 'msg91' . ' ' . 'sms');
        }

        if ($enabled === true || $enabled === false) {
            $provider->setAttribute('enabled', $enabled);
        }

        $credentials = $provider->getAttribute('credentials');

        if (!empty($senderId)) {
            $credentials['senderId'] = $senderId;
        }

        if (!empty($authKey)) {
            $credentials['authKey'] = $authKey;
        }

        $provider->setAttribute('credentials', $credentials);

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/telesign/:id')
    ->desc('Update Telesign Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateTelesignProvider')
    ->label('sdk.description', '/docs/references/messaging/update-telesign-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('username', '', new Text(0), 'Telesign username.', true)
    ->param('password', '', new Text(0), 'Telesign password.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, ?bool $enabled, string $username, string $password, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'telesign') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE . $providerAttr);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
            $provider->setAttribute('search', $provider->getId() . ' ' . $name . ' ' . 'telesign' . ' ' . 'sms');
        }

        if ($enabled === true || $enabled === false) {
            $provider->setAttribute('enabled', $enabled);
        }

        $credentials = $provider->getAttribute('credentials');

        if (!empty($username)) {
            $credentials['username'] = $username;
        }

        if (!empty($password)) {
            $credentials['password'] = $password;
        }

        $provider->setAttribute('credentials', $credentials);

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/textmagic/:id')
    ->desc('Update Textmagic Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateTextmagicProvider')
    ->label('sdk.description', '/docs/references/messaging/update-textmagic-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('username', '', new Text(0), 'Textmagic username.', true)
    ->param('apiKey', '', new Text(0), 'Textmagic apiKey.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, ?bool $enabled, string $username, string $apiKey, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'text-magic') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE . $providerAttr);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
            $provider->setAttribute('search', $provider->getId() . ' ' . $name . ' ' . 'textmagic' . ' ' . 'sms');
        }

        if ($enabled === true || $enabled === false) {
            $provider->setAttribute('enabled', $enabled);
        }

        $credentials = $provider->getAttribute('credentials');

        if (!empty($username)) {
            $credentials['username'] = $username;
        }

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        $provider->setAttribute('credentials', $credentials);

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/twilio/:id')
    ->desc('Update Twilio Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateTwilioProvider')
    ->label('sdk.description', '/docs/references/messaging/update-twilio-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('accountSid', null, new Text(0), 'Twilio account secret ID.', true)
    ->param('authToken', null, new Text(0), 'Twilio authentication token.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, ?bool $enabled, string $accountSid, string $authToken, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'twilio') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE . $providerAttr);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
            $provider->setAttribute('search', $provider->getId() . ' ' . $name . ' ' . 'twilio' . ' ' . 'sms');
        }

        if ($enabled === true || $enabled === false) {
            $provider->setAttribute('enabled', $enabled);
        }

        $credentials = $provider->getAttribute('credentials');

        if (!empty($accountSid)) {
            $credentials['accountSid'] = $accountSid;
        }

        if (!empty($authToken)) {
            $credentials['authToken'] = $authToken;
        }

        $provider->setAttribute('credentials', $credentials);

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/vonage/:id')
    ->desc('Update Vonage Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateVonageProvider')
    ->label('sdk.description', '/docs/references/messaging/update-vonage-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('apiKey', '', new Text(0), 'Vonage API key.', true)
    ->param('apiSecret', '', new Text(0), 'Vonage API secret.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, ?bool $enabled, string $apiKey, string $apiSecret, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'vonage') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE . $providerAttr);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
            $provider->setAttribute('search', $provider->getId() . ' ' . $name . ' ' . 'vonage' . ' ' . 'sms');
        }

        if ($enabled === true || $enabled === false) {
            $provider->setAttribute('enabled', $enabled);
        }

        $credentials = $provider->getAttribute('credentials');

        if (!empty($apiKey)) {
            $credentials['apiKey'] = $apiKey;
        }

        if (!empty($apiSecret)) {
            $credentials['apiSecret'] = $apiSecret;
        }

        $provider->setAttribute('credentials', $credentials);

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/fcm/:id')
    ->desc('Update FCM Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateFCMProvider')
    ->label('sdk.description', '/docs/references/messaging/update-fcm-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('serverKey', '', new Text(0), 'FCM Server Key.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, ?bool $enabled, string $serverKey, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'fcm') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE . $providerAttr);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
            $provider->setAttribute('search', $provider->getId() . ' ' . $name . ' ' . 'fcm' . ' ' . 'push');
        }

        if ($enabled === true || $enabled === false) {
            $provider->setAttribute('enabled', $enabled);
        }

        if (!empty($serverKey)) {
            $provider->setAttribute('credentials', ['serverKey' => $serverKey]);
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });


App::patch('/v1/messaging/providers/apns/:id')
    ->desc('Update APNS Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateAPNSProvider')
    ->label('sdk.description', '/docs/references/messaging/update-apns-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('authKey', '', new Text(0), 'APNS authentication key.', true)
    ->param('authKeyId', '', new Text(0), 'APNS authentication key ID.', true)
    ->param('teamId', '', new Text(0), 'APNS team ID.', true)
    ->param('bundleId', '', new Text(0), 'APNS bundle ID.', true)
    ->param('endpoint', '', new Text(0), 'APNS endpoint.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, ?bool $enabled, string $authKey, string $authKeyId, string $teamId, string $bundleId, string $endpoint, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'apns') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE . $providerAttr);
        }

        if (!empty($name)) {
            $provider->setAttribute('name', $name);
            $provider->setAttribute('search', $provider->getId() . ' ' . $name . ' ' . 'apns' . ' ' . 'push');
        }

        if ($enabled === true || $enabled === false) {
            $provider->setAttribute('enabled', $enabled);
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

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::delete('/v1/messaging/providers/:id')
    ->desc('Delete Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.delete')
    ->label('audits.resource', 'providers/{request.id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'deleteProvider')
    ->label('sdk.description', '/docs/references/messaging/delete-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('id', '', new UID(), 'Provider ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $dbForProject->deleteDocument('providers', $provider->getId());

        $response->noContent();
    });

App::post('/v1/messaging/messages/email')
    ->desc('Send an email.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'messages.create')
    ->label('audits.resource', 'messages/{response.$id}')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'sendEmail')
    ->label('sdk.description', '/docs/references/messaging/send-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('providerId', '', new UID(), 'Email Provider ID.')
    ->param('to', [], new ArrayList(new Text(65535)), 'List of Topic IDs or List of User IDs or List of Target IDs.')
    ->param('subject', '', new Text(998), 'Email Subject.')
    ->param('description', '', new Text(256), 'Description for Message.', true)
    ->param('content', '', new Text(65407), 'Email Content.')
    ->param('html', false, new Boolean(), 'Is content of type HTML', true)
    ->inject('dbForProject')
    ->inject('project')
    ->inject('messaging')
    ->inject('response')
    ->action(function (string $messageId, string $providerId, array $to, string $subject, string $description, string $content, string $html, Database $dbForProject, Document $project, Messaging $messaging, Response $response) {
        $messageId = $messageId == 'unique()' ? ID::unique() : $messageId;

        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $message = $dbForProject->createDocument('messages', new Document([
            '$id' => $messageId,
            'providerId' => $provider->getId(),
            'providerInternalId' => $provider->getInternalId(),
            'to' => $to,
            'data' => [
                'subject' => $subject,
                'content' => $content,
                'html' => $html,
                'description' => $description,
            ],
            'status' => 'processing',
            'search' => $messageId . ' ' . $description . ' ' . $subject,
        ]));

        $messaging
            ->setMessageId($message->getId())
            ->setProject($project)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::get('/v1/messaging/messages/:id')
    ->desc('Get Message')
    ->groups(['api', 'messaging'])
    ->label('scope', 'messages.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'getMessage')
    ->label('sdk.description', '/docs/references/messaging/get-message.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('id', '', new UID(), 'Message ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, Database $dbForProject, Response $response) {
        $message = $dbForProject->getDocument('messages', $id);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        $response->dynamic($message, Response::MODEL_MESSAGE);
    });
