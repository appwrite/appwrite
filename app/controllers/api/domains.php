<?php

use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Validator\Event;
use Appwrite\Network\Validator\CNAME;
use Utopia\Validator\Domain as DomainValidator;
use Appwrite\Network\Validator\Origin;
use Utopia\Validator\URL;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Query;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Registry\Registry;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::init()
  ->groups(['domains'])
  ->inject('domain')
  ->action(function (Document $domain) {
});

App::post('/v1/domains')
  ->action(function () {

});

App::post('/v1/domains/purchase')
  ->action(function () {

});

App::post('/v1/domains/transfer/in')
  ->action(function () {

});

App::post('/v1/domains/transfer/out')
  ->action(function () {

});

App::get('/v1/domains')
  ->action(function () {

});

App::get('/v1/domains/:domainId')
  ->action(function () {

});

App::patch('/v1/domains/:domainId/nameservers')
  ->action(function () {

});

App::patch('/v1/domains/:domainId/project')
  ->action(function () {

});

App::delete('/v1/domains/:domainId')
  ->action(function () {

});