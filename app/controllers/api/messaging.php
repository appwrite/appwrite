<?php

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Datetime;
use Utopia\Validator\ArrayList;
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

App::post('/v1/messaging/messages/email')
  ->desc('Send an email.')
  ->groups(['api', 'messaging'])
  ->label('event', 'messages.create')
  ->label('scope', 'messages.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN, APP_AUTH_TYPE_KEY])
  ->label('sdk.namespace', 'messaging')
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
      'search' => null
    ]));

    $eventsInstance->setParam('messageId', $message->getId());

    $response
      ->setStatusCode(Response::STATUS_CODE_CREATED)
      ->dynamic($provider, Response::MODEL_MESSAGE);
  });
