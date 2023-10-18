<?php

use Appwrite\Event\Delete;
use Appwrite\Event\Messaging;
use Appwrite\Extend\Exception;
use Appwrite\Permission;
use Appwrite\Role;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Messages;
use Appwrite\Utopia\Database\Validator\Queries\Providers;
use Appwrite\Utopia\Database\Validator\Queries\Subscribers;
use Appwrite\Utopia\Database\Validator\Queries\Topics;
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
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
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
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
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
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
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
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
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
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
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
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
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
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
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
    ->label('sdk.method', 'createFcmProvider')
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
                Query::equal('type', ['push'])
            ]))
        ) {
            $provider->setAttribute('default', true);
        }

        try {
            $provider = $dbForProject->createDocument('providers', $provider);
        } catch (DuplicateException) {
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
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
    ->label('sdk.method', 'createApnsProvider')
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
            throw new Exception(Exception::PROVIDER_ALREADY_EXISTS);
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
            $cursorDocument = Authorization::skip(fn () => $dbForProject->getDocument('providers', $providerId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Provider '{$providerId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];
        $response->dynamic(new Document([
            'total' => $dbForProject->count('providers', $filterQueries, APP_LIMIT_COUNT),
            'providers' => $dbForProject->find('providers', $queries),
        ]), Response::MODEL_PROVIDER_LIST);
    });

App::get('/v1/messaging/providers/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('isEuRegion', null, new Boolean(), 'Set as eu region.', true)
    ->param('from', '', new Text(256), 'Sender Email Address.', true)
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.', true)
    ->param('domain', '', new Text(0), 'Mailgun Domain.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, ?bool $isEuRegion, string $from, string $apiKey, string $domain, Database $dbForProject, Response $response) {
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

App::patch('/v1/messaging/providers/sendgrid/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('apiKey', '', new Text(0), 'Sendgrid API key.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $apiKey, Database $dbForProject, Response $response) {
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

App::patch('/v1/messaging/providers/msg91/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('senderId', '', new Text(0), 'Msg91 Sender ID.', true)
    ->param('authKey', '', new Text(0), 'Msg91 Auth Key.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $senderId, string $authKey, Database $dbForProject, Response $response) {
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

App::patch('/v1/messaging/providers/telesign/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('username', '', new Text(0), 'Telesign username.', true)
    ->param('password', '', new Text(0), 'Telesign password.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $username, string $password, Database $dbForProject, Response $response) {
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

App::patch('/v1/messaging/providers/textmagic/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('username', '', new Text(0), 'Textmagic username.', true)
    ->param('apiKey', '', new Text(0), 'Textmagic apiKey.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $username, string $apiKey, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'text-magic') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
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

App::patch('/v1/messaging/providers/twilio/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('accountSid', null, new Text(0), 'Twilio account secret ID.', true)
    ->param('authToken', null, new Text(0), 'Twilio authentication token.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $accountSid, string $authToken, Database $dbForProject, Response $response) {
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

App::patch('/v1/messaging/providers/vonage/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('enabled', null, new Boolean(), 'Set as enabled.', true)
    ->param('apiKey', '', new Text(0), 'Vonage API key.', true)
    ->param('apiSecret', '', new Text(0), 'Vonage API secret.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $apiKey, string $apiSecret, Database $dbForProject, Response $response) {
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

App::patch('/v1/messaging/providers/fcm/:providerId')
    ->desc('Update FCM Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
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
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $serverKey, Database $dbForProject, Response $response) {
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


App::patch('/v1/messaging/providers/apns/:providerId')
    ->desc('Update APNS Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
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
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, ?bool $enabled, string $authKey, string $authKeyId, string $teamId, string $bundleId, string $endpoint, Database $dbForProject, Response $response) {
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

App::delete('/v1/messaging/providers/:providerId')
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
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $dbForProject->deleteDocument('providers', $provider->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_NOCONTENT)
            ->noContent();
    });

App::post('/v1/messaging/topics')
    ->desc('Create a topic.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'topics.create')
    ->label('audits.resource', 'topics/{response.$id}')
    ->label('scope', 'topics.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createTopic')
    ->label('sdk.description', '/docs/references/messaging/create-topic.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOPIC)
    ->param('topicId', '', new CustomId(), 'Topic ID. Choose a custom Topic ID or a new Topic ID.')
    ->param('providerId', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Topic Name.')
    ->param('description', '', new Text(2048), 'Topic Description.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $topicId, string $providerId, string $name, string $description, Database $dbForProject, Response $response) {
        $topicId = $topicId == 'unique()' ? ID::unique() : $topicId;
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $topic = new Document([
            '$id' => $topicId,
            'providerId' => $providerId,
            'providerInternalId' => $provider->getInternalId(),
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
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (array $queries, Database $dbForProject, Response $response) {
        $queries = Query::parseQueries($queries);

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

        $filterQueries = Query::groupByType($queries)['filters'];
        $response->dynamic(new Document([
            'total' => $dbForProject->count('topics', $filterQueries, APP_LIMIT_COUNT),
            'topics' => $dbForProject->find('topics', $queries),
        ]), Response::MODEL_TOPIC_LIST);
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
    ->label('audits.event', 'topics.update')
    ->label('audits.resource', 'topics/{response.$id}')
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
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $topicId, string $name, string $description, Database $dbForProject, Response $response) {
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

        $response
            ->dynamic($topic, Response::MODEL_TOPIC);
    });

App::delete('/v1/messaging/topics/:topicId')
    ->desc('Delete a topic.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'topics.delete')
    ->label('audits.resource', 'topics/{request.topicId}')
    ->label('scope', 'topics.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'deleteTopic')
    ->label('sdk.description', '/docs/references/messaging/delete-topic.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('topicId', '', new UID(), 'Topic ID.')
    ->inject('dbForProject')
    ->inject('deletes')
    ->inject('response')
    ->action(function (string $topicId, Database $dbForProject, Delete $deletes, Response $response) {
        $topic = $dbForProject->getDocument('topics', $topicId);

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $dbForProject->deleteDocument('topics', $topicId);

        $deletes
            ->setType(DELETE_TYPE_SUBSCRIBERS)
            ->setDocument($topic);

        $response
            ->setStatusCode(Response::STATUS_CODE_NOCONTENT)
            ->noContent();
    });

App::post('/v1/messaging/topics/:topicId/subscribers')
    ->desc('Adds a Subscriber to a Topic.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'subscribers.create')
    ->label('audits.resource', 'subscribers/{response.$id}')
    ->label('scope', 'subscribers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_JWT, APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createSubscriber')
    ->label('sdk.description', '/docs/references/messaging/create-subscriber.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBSCRIBER)
    ->param('subscriberId', '', new CustomId(), 'Subscriber ID. Choose a custom Topic ID or a new Topic ID.')
    ->param('topicId', '', new UID(), 'Topic ID.')
    ->param('targetId', '', new UID(), 'Target ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $subscriberId, string $topicId, string $targetId, Database $dbForProject, Response $response) {
        $subscriberId = $subscriberId == 'unique()' ? ID::unique() : $subscriberId;

        $topic = Authorization::skip(fn () => $dbForProject->getDocument('topics', $topicId));

        if ($topic->isEmpty()) {
            throw new Exception(Exception::TOPIC_NOT_FOUND);
        }

        $target = Authorization::skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        $subscriber = new Document([
            '$id' => $subscriberId,
            '$permissions' => [
                Permission::read(Role::user($target->getAttribute('userId'))),
                Permission::delete(Role::user($target->getAttribute('userId'))),
            ],
            'topicId' => $topicId,
            'topicInternalId' => $topic->getInternalId(),
            'targetId' => $targetId,
            'targetInternalId' => $target->getInternalId(),
        ]);

        try {
            $subscriber = $dbForProject->createDocument('subscribers', $subscriber);
            $dbForProject->deleteCachedDocument('topics', $topicId);
        } catch (DuplicateException) {
            throw new Exception(Exception::SUBSCRIBER_ALREADY_EXISTS);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($subscriber, Response::MODEL_SUBSCRIBER);
    });

App::get('/v1/messaging/topics/:topicId/subscribers')
    ->desc('List topic\'s subscribers.')
    ->groups(['api', 'messaging'])
    ->label('scope', 'subscribers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listSubscribers')
    ->label('sdk.description', '/docs/references/messaging/list-subscribers.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBSCRIBER_LIST)
    ->param('topicId', '', new UID(), 'Topic ID.')
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

        $filterQueries = Query::groupByType($queries)['filters'];

        $response
            ->dynamic(new Document([
                'subscribers' => $dbForProject->find('subscribers', $queries),
                'total' => $dbForProject->count('subscribers', $filterQueries, APP_LIMIT_COUNT),
            ]), Response::MODEL_SUBSCRIBER_LIST);
    });

App::get('/v1/messaging/topics/:topicId/subscriber/:subscriberId')
    ->desc('Get a topic\'s subscriber.')
    ->groups(['api', 'messaging'])
    ->label('scope', 'subscribers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'getSubscriber')
    ->label('sdk.description', '/docs/references/messaging/get-subscriber.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBSCRIBER)
    ->param('topicId', '', new UID(), 'Topic ID.')
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

        $response
            ->dynamic($subscriber, Response::MODEL_SUBSCRIBER);
    });

App::delete('/v1/messaging/topics/:topicId/subscriber/:subscriberId')
    ->desc('Delete a Subscriber from a Topic.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'subscribers.delete')
    ->label('audits.resource', 'subscribers/{request.subscriberId}')
    ->label('scope', 'subscribers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_JWT, APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'deleteSubscriber')
    ->label('sdk.description', '/docs/references/messaging/delete-subscriber.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('topicId', '', new UID(), 'Topic ID.')
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
        $subscriber = $dbForProject->deleteDocument('subscribers', $subscriberId);
        $dbForProject->deleteCachedDocument('topics', $topicId);

        $response
            ->setStatusCode(Response::STATUS_CODE_NOCONTENT)
            ->noContent();
    });

App::post('/v1/messaging/messages/email')
    ->desc('Create an email.')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'messages.create')
    ->label('audits.resource', 'messages/{response.$id}')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createEmail')
    ->label('sdk.description', '/docs/references/messaging/create-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new CustomId(), 'Message ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('providerId', '', new UID(), 'Email Provider ID.')
    ->param('to', [], new ArrayList(new Text(Database::LENGTH_KEY)), 'List of Topic IDs or List of User IDs or List of Target IDs.')
    ->param('subject', '', new Text(998), 'Email Subject.')
    ->param('description', '', new Text(256), 'Description for Message.', true)
    ->param('content', '', new Text(64230), 'Email Content.')
    ->param('status', 'processing', new WhiteList(['draft', 'processing']), 'Message Status. Value must be either draft or processing.', true)
    ->param('html', false, new Boolean(), 'Is content of type HTML', true)
    ->param('deliveryTime', null, new DatetimeValidator(requireDateInFuture: true), 'Delivery time for message in ISO 8601 format. DateTime value must be in future.', true)
    ->inject('dbForProject')
    ->inject('project')
    ->inject('messaging')
    ->inject('response')
    ->action(function (string $messageId, string $providerId, array $to, string $subject, string $description, string $content, string $status, bool $html, ?string $deliveryTime, Database $dbForProject, Document $project, Messaging $messaging, Response $response) {
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
            'status' => $status,
            'search' => $messageId . ' ' . $description . ' ' . $subject,
        ]));

        if ($status === 'processing') {
            $messaging
                ->setMessageId($message->getId())
                ->setProject($project);

            if (!empty($deliveryTime)) {
                $messaging
                    ->setDeliveryTime($deliveryTime)
                    ->schedule();
            } else {
                $messaging->trigger();
            }
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($message, Response::MODEL_MESSAGE);
    });

App::get('/v1/messaging/messages')
    ->desc('List Messages')
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
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (array $queries, Database $dbForProject, Response $response) {
        $queries = Query::parseQueries($queries);

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

        $filterQueries = Query::groupByType($queries)['filters'];
        $response->dynamic(new Document([
            'total' => $dbForProject->count('messages', $filterQueries, APP_LIMIT_COUNT),
            'messages' => $dbForProject->find('messages', $queries),
        ]), Response::MODEL_MESSAGE_LIST);
    });

App::get('/v1/messaging/messages/:messageId')
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
    ->label('audits.event', 'messages.update')
    ->label('audits.resource', 'messages/{response.$id}')
    ->label('scope', 'messages.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/messaging/update-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MESSAGE)
    ->param('messageId', '', new UID(), 'Message ID.')
    ->param('to', [], new ArrayList(new Text(Database::LENGTH_KEY)), 'List of Topic IDs or List of User IDs or List of Target IDs.', true)
    ->param('subject', '', new Text(998), 'Email Subject.', true)
    ->param('description', '', new Text(256), 'Description for Message.', true)
    ->param('content', '', new Text(64230), 'Email Content.', true)
    ->param('status', '', new WhiteList(['draft', 'processing']), 'Message Status. Value must be either draft or processing.', true)
    ->param('html', false, new Boolean(), 'Is content of type HTML', true)
    ->param('deliveryTime', null, new DatetimeValidator(requireDateInFuture: true), 'Delivery time for message in ISO 8601 format. DateTime value must be in future.', true)
    ->inject('dbForProject')
    ->inject('project')
    ->inject('messaging')
    ->inject('response')
    ->action(function (string $messageId, array $to, string $subject, string $description, string $content, string $status, bool $html, ?string $deliveryTime, Database $dbForProject, Document $project, Messaging $messaging, Response $response) {
        $message = $dbForProject->getDocument('messages', $messageId);

        if ($message->isEmpty()) {
            throw new Exception(Exception::MESSAGE_NOT_FOUND);
        }

        if ($message->getAttribute('status') === 'sent') {
            throw new Exception(Exception::MESSAGE_ALREADY_SENT);
        }

        if (\count($to) > 0) {
            $message->setAttribute('to', $to);
        }

        $data = $message->getAttribute('data');

        if (!empty($subject)) {
            $data['subject'] = $subject;
        }

        if (!empty($content)) {
            $data['content'] = $content;
        }

        if (!empty($description)) {
            $data['description'] = $description;
        }

        if (!empty($html)) {
            $data['html'] = $html;
        }

        $message->setAttribute('data', $data);
        $message->setAttribute('search', $message->getId() . ' ' . $data['description'] . ' ' . $data['subject']);

        if (!empty($status)) {
            $message->setAttribute('status', $status);
        }

        $message = $dbForProject->updateDocument('messages', $message->getId(), $message);

        if ($status === 'processing') {
            $messaging
                ->setMessageId($message->getId())
                ->setProject($project);

            if (!empty($deliveryTime)) {
                $messaging
                ->setDeliveryTime($deliveryTime)
                ->schedule();
            } else {
                $messaging->trigger();
            }
        }

        $response
            ->dynamic($message, Response::MODEL_MESSAGE);
    });
