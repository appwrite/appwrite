<?php

use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Validator\Text;

/**
 * Email Providers 
 */
App::post('/v1/messaging/providers/mailgun')
  ->desc('Create Mailgun Provider')
  ->groups(['api', 'messaging'])
  ->label('event', 'messages.providers.create')
  ->label('scope', 'providers.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'messaging')
  ->label('sdk.description', '/docs/references/messaging/create-provider-mailgun.md')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_PROVIDER)
  ->param('name', '', new Text(128), 'Provider name.')
  ->param('apiKey', null, new Text(0), 'Mailgun API Key.', true)
  ->param('domain', null, new Text(0), 'Mailgun Domain.', true)
  ->inject('dbForProject')
  ->inject('response')
  ->action(function (string $name, string $apiKey, string $domain, Database $dbForProject, Response $response) {
    $provider = $dbForProject->getDocument('providers', '64e33e70dd07f0d03efb');
    $provider = $dbForProject->createDocument('providers', new Document([
      'name' => $name,
      'provider' => 'Mailgun',
      'type' => 'email',
      'credentials' => [
        'apiKey' => $apiKey,
        'domain' => $domain
      ],
    ]));
    $response
      ->setStatusCode(Response::STATUS_CODE_CREATED)
      ->dynamic($provider, Response::MODEL_PROVIDER);
  });
