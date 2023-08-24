<?php

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Providers;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime;
use Utopia\Database\Validator\UID;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

App::get('/v1/messaging/providers')
    ->desc('List Providers')
    ->groups(['api', 'messaging'])
    ->label('scope', 'providers.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'listProviders')
    ->label('sdk.description', '/docs/references/messaging/list-providers.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER_LIST)
    ->param('queries', [], new Providers(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of '.APP_LIMIT_ARRAY_PARAMS_SIZE.' queries are allowed, each '.APP_LIMIT_ARRAY_ELEMENT_SIZE.' characters long. You may filter on the following attributes: '.implode(', ', Providers::ALLOWED_ATTRIBUTES), true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (array $queries, Database $dbForProject, Response $response) {
        $queries = Query::parseQueries($queries);

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        $cursor = reset($cursor);

        if ($cursor) {
            $providerId = $cursor->getValue();
            $cursorDocument = Authorization::skip(fn () => $dbForProject->find('providers', [
                Query::equal('$id', [$providerId]),
                Query::limit(1),
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
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'getProvider')
    ->label('sdk.description', '/docs/references/messaging/get-provider.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', null, new UID(), 'Provider ID.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $response->dynamic($provider, Response::MODEL_PROVIDER);
    });

/**
 * Email Providers
 */
App::post('/v1/messaging/providers/mailgun')
    ->desc('Create Mailgun Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createProviderMailgun')
    ->label('sdk.description', '/docs/references/messaging/create-provider-mailgun.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.')
    ->param('domain', '', new Text(0), 'Mailgun Domain.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $apiKey, string $domain, Database $dbForProject, Response $response) {
		$providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
		$provider = $dbForProject->createDocument('providers', new Document([
			'$id' => $providerId,
			'name' => $name,
            'provider' => 'mailgun',
            'type' => 'email',
            'credentials' => [
                'apiKey' => $apiKey,
                'domain' => $domain,
            ],
        ]));
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/:id/mailgun')
    ->desc('Update Mailgun Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateProviderMailgun')
    ->label('sdk.description', '/docs/references/messaging/update-provider-mailgun.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('apiKey', '', new Text(0), 'Mailgun API Key.', true)
    ->param('domain', '', new Text(0), 'Mailgun Domain.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, string $apiKey, string $domain, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'mailgun') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE.$providerAttr);
        }

        if ($name) {
            $provider->setAttribute('name', $name);
        }

        if ($apiKey || $domain) {
            // Check if all five variables are present
            if ($apiKey && $domain) {
                $provider->setAttribute('credentials', [
                    'apiKey' => $apiKey,
                    'domain' => $domain,
                ]);
            } else {
                // Not all credential params are present
                throw new Exception(Exception::DOCUMENT_MISSING_DATA);
            }
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);
        $dbForProject->deleteCachedDocument('providers', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/sendgrid')
    ->desc('Create Sendgrid Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createProviderSendgrid')
    ->label('sdk.description', '/docs/references/messaging/create-provider-sendgrid.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('apiKey', '', new Text(0), 'Sendgrid API key.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $apiKey, Database $dbForProject, Response $response) {
		$providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
		$provider = $dbForProject->createDocument('providers', new Document([
			'$id' => $providerId,
			'name' => $name,
            'provider' => 'sendgrid',
            'type' => 'email',
            'credentials' => [
                'apiKey' => $apiKey,
            ],
        ]));
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/:id/sendgrid')
    ->desc('Update Sendgrid Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateProviderSendgrid')
    ->label('sdk.description', '/docs/references/messaging/update-provider-sendgrid.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('apiKey', '', new Text(0), 'Sendgrid API key.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, string $apiKey, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'sendgrid') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE.$providerAttr);
        }

        if ($name) {
            $provider->setAttribute('name', $name);
        }

        if ($apiKey) {
            $provider->setAttribute('credentials', [
                'apiKey' => $apiKey,
            ]);
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);
        $dbForProject->deleteCachedDocument('providers', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

/**
 * SMS Providers
 */
App::post('/v1/messaging/providers/msg91')
    ->desc('Create Msg91 Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createProviderMsg91')
    ->label('sdk.description', '/docs/references/messaging/create-provider-msg91.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
	->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('senderId', '', new Text(0), 'Msg91 Sender ID.')
    ->param('authKey', '', new Text(0), 'Msg91 Auth Key.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $senderId, string $authKey, Database $dbForProject, Response $response) {
		$providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
		$provider = $dbForProject->createDocument('providers', new Document([
			'$id' => $providerId,
			'name' => $name,
            'provider' => 'msg91',
            'type' => 'sms',
            'credentials' => [
                'senderId' => $senderId,
                'authKey' => $authKey,
            ],
        ]));
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/:id/msg91')
    ->desc('Update Msg91 Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateProviderMsg91')
    ->label('sdk.description', '/docs/references/messaging/update-provider-msg91.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('senderId', '', new Text(0), 'Msg91 Sender ID.', true)
    ->param('authKey', '', new Text(0), 'Msg91 Auth Key.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, string $senderId, string $authKey, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'msg91') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE.$providerAttr);
        }

        if ($name) {
            $provider->setAttribute('name', $name);
        }

        if ($senderId || $authKey) {
            // Check if all five variables are present
            if ($senderId && $authKey) {
                $provider->setAttribute('credentials', [
                    'senderId' => $senderId,
                    'authKey' => $authKey,
                ]);
            } else {
                // Not all credential params are present
                throw new Exception(Exception::DOCUMENT_MISSING_DATA);
            }
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);
        $dbForProject->deleteCachedDocument('providers', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/telesign')
    ->desc('Create Telesign Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createProviderTelesign')
    ->label('sdk.description', '/docs/references/messaging/create-provider-telesign.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('username', '', new Text(0), 'Telesign username.')
    ->param('password', '', new Text(0), 'Telesign password.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $username, string $password, Database $dbForProject, Response $response) {
		$providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
		$provider = $dbForProject->createDocument('providers', new Document([
			'$id' => $providerId,
			'name' => $name,
            'provider' => 'telesign',
            'type' => 'sms',
            'credentials' => [
                'username' => $username,
                'password' => $password,
            ],
        ]));
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/:id/telesign')
    ->desc('Update Telesign Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateProviderTelesign')
    ->label('sdk.description', '/docs/references/messaging/update-provider-telesign.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('username', '', new Text(0), 'Telesign username.', true)
    ->param('password', '', new Text(0), 'Telesign password.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, string $username, string $password, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'telesign') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE.$providerAttr);
        }

        if ($name) {
            $provider->setAttribute('name', $name);
        }

        if ($username || $password) {
            // Check if all five variables are present
            if ($username && $password) {
                $provider->setAttribute('credentials', [
                    'username' => $username,
                    'password' => $password,
                ]);
            } else {
                // Not all credential params are present
                throw new Exception(Exception::DOCUMENT_MISSING_DATA);
            }
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);
        $dbForProject->deleteCachedDocument('providers', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/textmagic')
    ->desc('Create Textmagic Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createProviderTextmagic')
    ->label('sdk.description', '/docs/references/messaging/create-provider-textmagic.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('username', '', new Text(0), 'Textmagic username.')
    ->param('apiKey', '', new Text(0), 'Textmagic apiKey.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $username, string $apiKey, Database $dbForProject, Response $response) {
		$providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
		$provider = $dbForProject->createDocument('providers', new Document([
			'$id' => $providerId,
			'name' => $name,
            'provider' => 'text-magic',
            'type' => 'sms',
            'credentials' => [
                'username' => $username,
                'apiKey' => $apiKey,
            ],
        ]));
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/:id/textmagic')
    ->desc('Update Textmagic Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateProviderTextmagic')
    ->label('sdk.description', '/docs/references/messaging/update-provider-textmagic.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('username', '', new Text(0), 'Textmagic username.', true)
    ->param('apiKey', '', new Text(0), 'Textmagic apiKey.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, string $username, string $apiKey, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'text-magic') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE.$providerAttr);
        }

        if ($name) {
            $provider->setAttribute('name', $name);
        }

        if ($username || $apiKey) {
            // Check if all five variables are present
            if ($username && $apiKey) {
                $provider->setAttribute('credentials', [
                    'username' => $username,
                    'apiKey' => $apiKey,
                ]);
            } else {
                // Not all credential params are present
                throw new Exception(Exception::DOCUMENT_MISSING_DATA);
            }
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);
        $dbForProject->deleteCachedDocument('providers', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/twilio')
    ->desc('Create Twilio Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createProviderTwilio')
    ->label('sdk.description', '/docs/references/messaging/create-provider-twilio.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('accountSid', '', new Text(0), 'Twilio account secret ID.')
    ->param('authToken', '', new Text(0), 'Twilio authentication token.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $accountSid, string $authToken, Database $dbForProject, Response $response) {
		$providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
		$provider = $dbForProject->createDocument('providers', new Document([
			'$id' => $providerId,
			'name' => $name,
            'provider' => 'twilio',
            'type' => 'sms',
            'credentials' => [
                'accountSid' => $accountSid,
                'authToken' => $authToken,
            ],
        ]));
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/:id/twilio')
    ->desc('Update Twilio Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateProviderTwilio')
    ->label('sdk.description', '/docs/references/messaging/update-provider-twilio.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('accountSid', null, new Text(0), 'Twilio account secret ID.', true)
    ->param('authToken', null, new Text(0), 'Twilio authentication token.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, string $accountSid, string $authToken, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'twilio') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE.$providerAttr);
        }

        if ($name) {
            $provider->setAttribute('name', $name);
        }

        if ($accountSid || $authToken) {
            // Check if all five variables are present
            if ($accountSid && $authToken) {
                $provider->setAttribute('credentials', [
                    'accountSid' => $accountSid,
                    'authToken' => $authToken,
                ]);
            } else {
                // Not all credential params are present
                throw new Exception(Exception::DOCUMENT_MISSING_DATA);
            }
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);
        $dbForProject->deleteCachedDocument('providers', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/vonage')
    ->desc('Create Vonage Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createProviderVonage')
    ->label('sdk.description', '/docs/references/messaging/create-provider-vonage.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('apiKey', '', new Text(0), 'Vonage API key.')
    ->param('apiSecret', '', new Text(0), 'Vonage API secret.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $apiKey, string $apiSecret, Database $dbForProject, Response $response) {
		$providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
		$provider = $dbForProject->createDocument('providers', new Document([
			'$id' => $providerId,
			'name' => $name,
            'provider' => 'vonage',
            'type' => 'sms',
            'credentials' => [
                'apiKey' => $apiKey,
                'apiSecret' => $apiSecret,
            ],
        ]));
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/:id/vonage')
    ->desc('Update Vonage Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateProviderVonage')
    ->label('sdk.description', '/docs/references/messaging/update-provider-vonage.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('apiKey', '', new Text(0), 'Vonage API key.', true)
    ->param('apiSecret', '', new Text(0), 'Vonage API secret.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, string $apiKey, string $apiSecret, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'vonage') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE.$providerAttr);
        }

        if ($name) {
            $provider->setAttribute('name', $name);
        }

        if ($apiKey || $apiSecret) {
            // Check if all five variables are present
            if ($apiKey && $apiSecret) {
                $provider->setAttribute('credentials', [
                    'apiKey' => $apiKey,
                    'apiSecret' => $apiSecret,
                ]);
            } else {
                // Not all credential params are present
                throw new Exception(Exception::DOCUMENT_MISSING_DATA);
            }
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);
        $dbForProject->deleteCachedDocument('providers', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

/**
 * Push Providers
 */
App::post('/v1/messaging/providers/fcm')
    ->desc('Create FCM Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createProviderFCM')
    ->label('sdk.description', '/docs/references/messaging/create-provider-fcm.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('serverKey', '', new Text(0), 'FCM Server Key.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $serverKey, Database $dbForProject, Response $response) {
		$providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
		$provider = $dbForProject->createDocument('providers', new Document([
			'$id' => $providerId,
			'name' => $name,
            'provider' => 'fcm',
            'type' => 'push',
            'credentials' => [
                'serverKey' => $serverKey,
            ],
        ]));
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/:id/fcm')
    ->desc('Update FCM Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateProviderFCM')
    ->label('sdk.description', '/docs/references/messaging/update-provider-fcm.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('serverKey', '', new Text(0), 'FCM Server Key.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, string $serverKey, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'fcm') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE.$providerAttr);
        }

        if ($name) {
            $provider->setAttribute('name', $name);
        }

        if ($serverKey) {
            $provider->setAttribute('credentials', ['serverKey' => $serverKey]);
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);
        $dbForProject->deleteCachedDocument('providers', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::post('/v1/messaging/providers/apns')
    ->desc('Create APNS Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.create')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'createProviderAPNS')
    ->label('sdk.description', '/docs/references/messaging/create-provider-apns.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('providerId', '', new CustomId(), 'Provider ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Provider name.')
    ->param('authKey', '', new Text(0), 'APNS authentication key.')
    ->param('authKeyId', '', new Text(0), 'APNS authentication key ID.')
    ->param('teamId', '', new Text(0), 'APNS team ID.')
    ->param('bundleId', '', new Text(0), 'APNS bundle ID.')
    ->param('endpoint', '', new Text(0), 'APNS endpoint.')
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $providerId, string $name, string $authKey, string $authKeyId, string $teamId, string $bundleId, string $endpoint, Database $dbForProject, Response $response) {
		$providerId = $providerId == 'unique()' ? ID::unique() : $providerId;
		$provider = $dbForProject->createDocument('providers', new Document([
			'$id' => $providerId,
			'name' => $name,
            'provider' => 'apns',
            'type' => 'push',
            'credentials' => [
                'authKey' => $authKey,
                'authKeyId' => $authKeyId,
                'teamId' => $teamId,
                'bundleId' => $bundleId,
                'endpoint' => $endpoint,
            ],
        ]));
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::patch('/v1/messaging/providers/:id/apns')
    ->desc('Update APNS Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.update')
    ->label('audits.resource', 'providers/{response.$id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'messaging')
    ->label('sdk.method', 'updateProviderAPNS')
    ->label('sdk.description', '/docs/references/messaging/update-provider-apns.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROVIDER)
    ->param('id', '', new UID(), 'Provider ID.')
    ->param('name', '', new Text(128), 'Provider name.', true)
    ->param('authKey', '', new Text(0), 'APNS authentication key.', true)
    ->param('authKeyId', '', new Text(0), 'APNS authentication key ID.', true)
    ->param('teamId', '', new Text(0), 'APNS team ID.', true)
    ->param('bundleId', '', new Text(0), 'APNS bundle ID.', true)
    ->param('endpoint', '', new Text(0), 'APNS endpoint.', true)
    ->inject('dbForProject')
    ->inject('response')
    ->action(function (string $id, string $name, string $authKey, string $authKeyId, string $teamId, string $bundleId, string $endpoint, Database $dbForProject, Response $response) {
        $provider = $dbForProject->getDocument('providers', $id);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }
        $providerAttr = $provider->getAttribute('provider');

        if ($providerAttr !== 'apns') {
            throw new Exception(Exception::PROVIDER_INCORRECT_TYPE.$providerAttr);
        }

        if ($name) {
            $provider->setAttribute('name', $name);
        }

        if ($authKey || $authKeyId || $teamId || $bundleId || $endpoint) {
            // Check if all five variables are present
            if ($authKey && $authKeyId && $teamId && $bundleId && $endpoint) {
                $provider->setAttribute('credentials', [
                    'authKey' => $authKey,
                    'authKeyId' => $authKeyId,
                    'teamId' => $teamId,
                    'bundleId' => $bundleId,
                    'endpoint' => $endpoint,
                ]);
            } else {
                // Not all credential params are present
                throw new Exception(Exception::DOCUMENT_MISSING_DATA);
            }
        }

        $provider = $dbForProject->updateDocument('providers', $provider->getId(), $provider);
        $dbForProject->deleteCachedDocument('providers', $provider->getId());

        $response
            ->dynamic($provider, Response::MODEL_PROVIDER);
    });

App::delete('/v1/messaging/providers/:id')
    ->desc('Delete Provider')
    ->groups(['api', 'messaging'])
    ->label('audits.event', 'providers.delete')
    ->label('audits.resource', 'providers/{request.id}')
    ->label('scope', 'providers.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
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

        $dbForProject->deleteCachedDocument('providers', $provider->getId());
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
    ->param('providerId', '', new Text(128), 'Email Provider ID.')
    ->param('to', [], new ArrayList(new Text(0)), 'Email Recepient.', true)
    ->param('subject', null, new Text(0), 'Email Subject.', true)
    ->param('content', null, new Text(0), 'Email Content.', true)
    ->param('from', null, new Text(0), 'Email from.', false)
    ->param('html', null, new Text(0), 'Is content of type HTML', false)
    ->param('deliveryTime', null, new Datetime(), 'Delivery time of the message', false)
    ->inject('dbForProject')
    ->inject('events')
    ->inject('response')
    ->action(function (string $providerId, string $to, string $subject, string $content, string $from, string $html, DateTime $deliveryTime, Database $dbForProject, Event $eventsInstance, Response $response) {
        $provider = $dbForProject->getDocument('providers', $providerId);

        if ($provider->isEmpty()) {
            throw new Exception(Exception::PROVIDER_NOT_FOUND);
        }

        $message = $dbForProject->createDocument('messages', new Document([
            'providerId' => $provider->getId(),
            'providerInternalId' => $provider->getInternalId(),
            'to' => $to,
            'data' => [
                'subject' => $subject,
                'content' => $content,
            ],
            'deliveryTime' => $deliveryTime,
            'deliveryError' => null,
            'deliveredTo' => null,
            'delivered' => false,
            'search' => null,
        ]));

        $eventsInstance->setParam('messageId', $message->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($provider, Response::MODEL_MESSAGE);
    });
