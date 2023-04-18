<?php

use Appwrite\Auth\Validator\Phone;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Email;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Contact;
use Utopia\Domains\Domain;
use Utopia\Domains\Registrar;
use Utopia\Validator\Domain as DomainValidator;
use Utopia\Validator\Text;

App::init()
  ->groups(['projects'])
  ->inject('project')
  ->action(function (Document $project) {
    if ($project->getId() !== 'console') {
        throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
    }
  });

App::post('/v1/domains/suggest')
  ->desc('Suggest domain names')
  ->groups(['api', 'projects'])
  ->label('scope', 'projects.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'create')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('domain', null, new DomainValidator(), 'Domain name')
  ->inject('response')
  ->inject('registrar')
  ->action(function (string $domain, $response, $registrar) {
      $domain = new Domain($domain);
      $suggestions = $registrar->suggest([$domain->getName()], [$domain->getTLD()]);

      $response->dynamic(new Document(['domains' => $suggestions]), Response::MODEL_DOMAIN_LIST);
  });

App::post('/v1/domains/available')
  ->desc('Checks if a domain is available for registration')
  ->groups(['api', 'projects'])
  ->label('scope', 'projects.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'create')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('domain', null, new DomainValidator(), 'Domain name')
  ->inject('response')
  ->inject('registrar')
  ->action(function (string $domain, $response, $registrar) {
      $domain = [
          'domain' => $domain,
          'available' => $registrar->available($domain),
      ];

      $response->dynamic(new Document(['domain' => $domain]), Response::MODEL_DOMAIN);
  });

App::post('/v1/domains')
  ->desc('Create a domain for 3rd party registrar, prompt to assign nameservers')
  ->groups(['api', 'projects'])
  ->label('scope', 'projects.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'create')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('projectId', '', new UID(), 'Project unique ID')
  ->param('domain', null, new DomainValidator(), 'Domain name')
  ->inject('response')
  ->inject('dbForConsole')
  ->inject('registrar')
  ->action(function (
      string $projectId,
      string $domain,
      Response $response,
      Database $dbForConsole,
      Registrar $registrar
  ) {
    if (! $registrar->available($domain)) {
        throw new Exception(Exception::DOMAIN_ALREADY_EXISTS);
    }

      $project = $dbForConsole->getDocument('projects', $projectId);

    if ($project->isEmpty()) {
        throw new Exception(Exception::PROJECT_NOT_FOUND);
    }

      $document = $dbForConsole->findOne('domains', [
          Query::equal('domain', [$domain]),
          Query::equal('projectInternalId', [$project->getInternalId()]),
      ]);

    if ($document && ! $document->isEmpty()) {
        throw new Exception(Exception::DOMAIN_ALREADY_EXISTS);
    }

      $domain = new Domain($domain);

      $domain = new Document([
          '$id' => ID::unique(),
          '$permissions' => [
              Permission::read(Role::any()),
              Permission::update(Role::any()),
              Permission::delete(Role::any()),
          ],
          'projectInternalId' => $project->getInternalId(),
          'projectId' => $project->getId(),
          'domain' => $domain->get(),
          'tld' => $domain->getSuffix(),
          'registerable' => $domain->getRegisterable(),
          'verification' => false,
          'certificateId' => null,
          'registered' => false,
      ]);

      $domain = $dbForConsole->createDocument('domains', $domain);

      $dbForConsole->deleteCachedDocument('projects', $project->getId());

      $response
          ->setStatusCode(Response::STATUS_CODE_CREATED)
          ->dynamic($domain, Response::MODEL_DOMAIN);
  });

App::post('/v1/domains/purchase')
  ->desc('Purchase, create and domain assign nameservers')
  ->groups(['api', 'projects'])
  ->label('scope', 'projects.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'create')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('projectId', '', new UID(), 'Project unique ID')
  ->param('domain', null, new DomainValidator(), 'Domain name')
  ->param('firstname', '', new Text(128), 'First name')
  ->param('lastname', '', new Text(128), 'Last name')
  ->param('phone', '', new Phone(), 'Phone number')
  ->param('email', '', new Email(), 'Email address')
  ->param('address1', '', new Text(128), 'Address')
  ->param('address2', '', new Text(128), 'Address 2')
  ->param('address3', '', new Text(128), 'Address 3')
  ->param('city', '', new Text(128), 'City')
  ->param('state', '', new Text(128), 'State')
  ->param('country', '', new Text(128), 'Country')
  ->param('postalcode', '', new Text(128), 'Postal code')
  ->param('org', '', new Text(128), 'Organization')
  ->inject('response')
  ->inject('dbForConsole')
  ->inject('registrar')
  ->action(function (
      string $projectId,
      string $domain,
      string $firstname,
      string $lastname,
      string $phone,
      string $email,
      string $address1,
      string $address2,
      string $address3,
      string $city,
      string $state,
      string $country,
      string $postalcode,
      string $org,
      Response $response,
      Database $dbForConsole,
      Registrar $registrar
  ) {
    if (! $registrar->available($domain)) {
        throw new Exception();
    }

      $contact = new Contact(
          $firstname,
          $lastname,
          $phone,
          $email,
          $address1,
          $address2,
          $address3,
          $city,
          $state,
          $country,
          $postalcode,
          $org,
          ''
      );

    try {
        $registrar->purchase($domain, [$contact]);
    } catch (Exception $e) {
        throw new Exception();
    }

      $project = $dbForConsole->getDocument('projects', $projectId);

    if ($project->isEmpty()) {
        throw new Exception(Exception::PROJECT_NOT_FOUND);
    }

      $document = $dbForConsole->findOne('domains', [
          Query::equal('domain', [$domain]),
          Query::equal('projectInternalId', [$project->getInternalId()]),
      ]);

    if ($document && ! $document->isEmpty()) {
        throw new Exception(Exception::DOMAIN_ALREADY_EXISTS);
    }

      $domain = new Domain($domain);

      $domain = new Document([
          '$id' => ID::unique(),
          '$permissions' => [
              Permission::read(Role::any()),
              Permission::update(Role::any()),
              Permission::delete(Role::any()),
          ],
          'projectInternalId' => $project->getInternalId(),
          'projectId' => $project->getId(),
          'domain' => $domain->get(),
          'tld' => $domain->getSuffix(),
          'registerable' => $domain->getRegisterable(),
          'verification' => false,
          'certificateId' => null,
          'registered' => true,
      ]);

      $domain = $dbForConsole->createDocument('domains', $domain);

      $dbForConsole->deleteCachedDocument('projects', $project->getId());

      $response
          ->setStatusCode(Response::STATUS_CODE_CREATED)
          ->dynamic($domain, Response::MODEL_DOMAIN);
  });

App::post('/v1/domains/transfer/in')
  ->desc('Transfer existing domain to Appwrite, and assign nameservers')
  ->groups(['api', 'projects'])
  ->label('scope', 'projects.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'create')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('projectId', '', new UID(), 'Project unique ID')
  ->param('domain', null, new DomainValidator(), 'Domain name')
  ->param('firstname', '', new Text(128), 'First name')
  ->param('lastname', '', new Text(128), 'Last name')
  ->param('phone', '', new Phone(), 'Phone number')
  ->param('email', '', new Email(), 'Email address')
  ->param('address1', '', new Text(128), 'Address')
  ->param('address2', '', new Text(128), 'Address 2')
  ->param('address3', '', new Text(128), 'Address 3')
  ->param('city', '', new Text(128), 'City')
  ->param('state', '', new Text(128), 'State')
  ->param('country', '', new Text(128), 'Country')
  ->param('postalcode', '', new Text(128), 'Postal code')
  ->param('org', '', new Text(128), 'Organization')
  ->inject('response')
  ->inject('dbForConsole')
  ->inject('registrar')
  ->action(function (
      string $projectId,
      string $domain,
      string $firstname,
      string $lastname,
      string $phone,
      string $email,
      string $address1,
      string $address2,
      string $address3,
      string $city,
      string $state,
      string $country,
      string $postalcode,
      string $org,
      Response $response,
      Database $dbForConsole,
      Registrar $registrar
  ) {
      $contact = new Contact(
          $firstname,
          $lastname,
          $phone,
          $email,
          $address1,
          $address2,
          $address3,
          $city,
          $state,
          $country,
          $postalcode,
          $org,
          ''
      );

    try {
        $registrar->transfer($domain, [$contact]);
    } catch (Exception $e) {
        throw new Exception();
    }

      $project = $dbForConsole->getDocument('projects', $projectId);

    if ($project->isEmpty()) {
        throw new Exception(Exception::PROJECT_NOT_FOUND);
    }

      $document = $dbForConsole->findOne('domains', [
          Query::equal('domain', [$domain]),
          Query::equal('projectInternalId', [$project->getInternalId()]),
      ]);

    if ($document && ! $document->isEmpty()) {
        throw new Exception(Exception::DOMAIN_ALREADY_EXISTS);
    }

      $domain = new Domain($domain);

      $domain = new Document([
          '$id' => ID::unique(),
          '$permissions' => [
              Permission::read(Role::any()),
              Permission::update(Role::any()),
              Permission::delete(Role::any()),
          ],
          'projectInternalId' => $project->getInternalId(),
          'projectId' => $project->getId(),
          'domain' => $domain->get(),
          'tld' => $domain->getSuffix(),
          'registerable' => $domain->getRegisterable(),
          'verification' => false,
          'certificateId' => null,
          'registered' => true,
      ]);

      $domain = $dbForConsole->createDocument('domains', $domain);

      $dbForConsole->deleteCachedDocument('projects', $project->getId());

      $response
          ->setStatusCode(Response::STATUS_CODE_CREATED)
          ->dynamic($domain, Response::MODEL_DOMAIN);
  });

App::post('/v1/domains/transfer/out')
  ->desc('Start transfer process for domain and generate code for transfer to 3rd party registrar')
  ->groups(['api', 'domains'])
  ->label('scope', 'projects.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'transferOut')
  ->label('sdk.method', 'create')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->inject('response')
  ->inject('user')
  ->action(function () {
      ///TODO
  });

App::get('/v1/domains')
  ->desc('List domains')
  ->groups(['api', 'domains'])
  ->label('scope', 'projects.read')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'list')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('projectId', '', new UID(), 'Project unique ID')
  ->inject('response')
  ->inject('dbForConsole')
  ->action(function (string $projectId, Response $response, Database $dbForConsole) {
      $project = $dbForConsole->getDocument('projects', $projectId);

    if ($project->isEmpty()) {
        throw new Exception(Exception::PROJECT_NOT_FOUND);
    }

      $domains = $dbForConsole->find('domains', [
          Query::equal('projectInternalId', [$project->getInternalId()]),
          Query::limit(5000),
      ]);

      $response->dynamic(new Document([
          'domains' => $domains,
          'total' => count($domains),
      ]), Response::MODEL_DOMAIN_LIST);
  });

App::get('/v1/domains/:domainId')
  ->desc('Get domain')
  ->groups(['api', 'domains'])
  ->label('scope', 'projects.read')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'list')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('projectId', '', new UID(), 'Project unique ID')
  ->param('domainId', '', new UID(), 'Domain unique ID')
  ->inject('response')
  ->inject('dbForConsole')
  ->action(function (string $projectId, string $domainId, Response $response, Database $dbForConsole) {
      $project = $dbForConsole->getDocument('projects', $projectId);

    if ($project->isEmpty()) {
        throw new Exception(Exception::PROJECT_NOT_FOUND);
    }

      $domain = $dbForConsole->findOne('domains', [
          Query::equal('_uid', [$domainId]),
          Query::equal('projectInternalId', [$project->getInternalId()]),
      ]);

    if ($domain === false || $domain->isEmpty()) {
        throw new Exception(Exception::DOMAIN_NOT_FOUND);
    }

      $response->dynamic($domain, Response::MODEL_DOMAIN);
  });

App::patch('/v1/domains/:domainId/nameservers')
  ->desc('Check namserver records and update them if needed')
  ->groups(['api', 'domains'])
  ->label('scope', 'projects.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'updateNameservers')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('domainId', '', new UID(), 'Domain unique ID')
  ->inject('response')
  ->inject('user')
  ->action(function () {
      ///Do we need/want?
  });

App::patch('/v1/domains/:domainId/project')
  ->desc('Move domain to a different project')
  ->groups(['api', 'domains'])
  ->label('scope', 'projects.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'updateProject')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('domainId', '', new UID(), 'Domain unique ID')
  ->inject('response')
  ->inject('user')
  ->action(function () {
      /// WAIT FOR TRANSFER SERVICE.
  });

App::delete('/v1/domains/:domainId')
  ->desc('Remove a domain')
  ->groups(['api', 'domains'])
  ->label('scope', 'projects.write')
  ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
  ->label('sdk.namespace', 'domains')
  ->label('sdk.method', 'list')
  ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
  ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
  ->label('sdk.response.model', Response::MODEL_DOMAIN)
  ->param('projectId', '', new UID(), 'Project unique ID')
  ->param('domainId', '', new UID(), 'Domain unique ID')
  ->inject('response')
  ->inject('dbForConsole')
  ->action(function (string $projectId, string $domainId, Response $response, Database $dbForConsole) {
      $project = $dbForConsole->getDocument('projects', $projectId);

    if ($project->isEmpty()) {
        throw new Exception(Exception::PROJECT_NOT_FOUND);
    }

      $domain = $dbForConsole->findOne('domains', [
          Query::equal('_uid', [$domainId]),
          Query::equal('projectInternalId', [$project->getInternalId()]),
      ]);

    if ($domain === false || $domain->isEmpty()) {
        throw new Exception(Exception::DOMAIN_NOT_FOUND);
    }

    if ($domain['registered'] === true) {
        throw new Exception();
    }

      $response->noContent();
  });
