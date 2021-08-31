<?php

use Appwrite\Auth\Auth;
use Appwrite\Database\Validator\Authorization;
use Utopia\App;
use Utopia\Exception;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;

App::init(function ($utopia, $request, $response, $project, $user, $register, $events, $audits, $usage, $deletes, $db) {
    /** @var Utopia\App $utopia */
    /** @var Utopia\Swoole\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Appwrite\Database\Document $project */
    /** @var Appwrite\Database\Document $user */
    /** @var Utopia\Registry\Registry $register */
    /** @var Appwrite\Event\Event $events */
    /** @var Appwrite\Event\Event $audits */
    /** @var Appwrite\Event\Event $usage */
    /** @var Appwrite\Event\Event $deletes */
    /** @var Appwrite\Event\Event $functions */

    Storage::setDevice('files', new Local(APP_STORAGE_UPLOADS.'/app-'.$project->getId()));
    Storage::setDevice('functions', new Local(APP_STORAGE_FUNCTIONS.'/app-'.$project->getId()));

    $route = $utopia->match($request);

    if (empty($project->getId()) && $route->getLabel('abuse-limit', 0) > 0) { // Abuse limit requires an active project scope
        throw new Exception('Missing or unknown project ID', 400);
    }

    /*
     * Abuse Check
     */
    $timeLimit = new TimeLimit($route->getLabel('abuse-key', 'url:{url},ip:{ip}'), $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600), $db);
    $timeLimit->setNamespace('app_'.$project->getId());
    $timeLimit
        ->setParam('{userId}', $user->getId())
        ->setParam('{userAgent}', $request->getUserAgent(''))
        ->setParam('{ip}', $request->getIP())
        ->setParam('{url}', $request->getHostname().$route->getPath())
    ;

    //TODO make sure we get array here

    foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
        if(!empty($value)) {
            $timeLimit->setParam('{param-'.$key.'}', (\is_array($value)) ? \json_encode($value) : $value);
        }
    }

    $abuse = new Abuse($timeLimit);

    if ($timeLimit->limit()) {
        $response
            ->addHeader('X-RateLimit-Limit', $timeLimit->limit())
            ->addHeader('X-RateLimit-Remaining', $timeLimit->remaining())
            ->addHeader('X-RateLimit-Reset', $timeLimit->time() + $route->getLabel('abuse-time', 3600))
        ;
    }

    $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::$roles);
    $isAppUser = Auth::isAppUser(Authorization::$roles);

    if (($abuse->check() // Route is rate-limited
        && App::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled') // Abuse is not diabled
        && (!$isAppUser && !$isPrivilegedUser)) // User is not an admin or API key
        {
        throw new Exception('Too many requests', 429);
    }

    /*
     * Background Jobs
     */
    $events
        ->setParam('projectId', $project->getId())
        ->setParam('webhooks', $project->getAttribute('webhooks', []))
        ->setParam('userId', $user->getId())
        ->setParam('event', $route->getLabel('event', ''))
        ->setParam('eventData', [])
        ->setParam('functionId', null)	
        ->setParam('executionId', null)	
        ->setParam('trigger', 'event')
    ;

    $audits
        ->setParam('projectId', $project->getId())
        ->setParam('userId', $user->getId())
        ->setParam('event', '')
        ->setParam('resource', '')
        ->setParam('userAgent', $request->getUserAgent(''))
        ->setParam('ip', $request->getIP())
        ->setParam('data', [])
    ;

    $usage
        ->setParam('projectId', $project->getId())
        ->setParam('httpRequest', 1)
        ->setParam('httpUrl', $request->getHostname().$request->getURI())
        ->setParam('httpMethod', $request->getMethod())
        ->setParam('networkRequestSize', 0)
        ->setParam('networkResponseSize', 0)
        ->setParam('storage', 0)
    ;
    
    $deletes
        ->setParam('projectId', $project->getId())
    ;

}, ['utopia', 'request', 'response', 'project', 'user', 'register', 'events', 'audits', 'usage', 'deletes', 'db'], 'api');


App::init(function ($utopia, $request, $response, $project, $user) {
    /** @var Utopia\App $utopia */
    /** @var Utopia\Swoole\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Appwrite\Database\Document $project */
    /** @var Appwrite\Database\Document $user */
    /** @var Utopia\Registry\Registry $register */
    /** @var Appwrite\Event\Event $events */
    /** @var Appwrite\Event\Event $audits */
    /** @var Appwrite\Event\Event $usage */
    /** @var Appwrite\Event\Event $deletes */
    /** @var Appwrite\Event\Event $functions */

    $route = $utopia->match($request);

    $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::$roles);
    $isAppUser = Auth::isAppUser(Authorization::$roles);

    if($isAppUser || $isPrivilegedUser) { // Skip limits for app and console devs
        return;
    }

    switch ($route->getLabel('auth.type', '')) {
        case 'emailPassword':
            if($project->getAttribute('usersAuthEmailPassword', true) === false) {
                throw new Exception('Email / Password authentication is disabled for this project', 501);
            }
            break;

        case 'magic-url':
            if($project->getAttribute('usersAuthMagicURL', true) === false) {
                throw new Exception('Magic URL authentication is disabled for this project', 501);
            }
            break;

        case 'anonymous':
            if($project->getAttribute('usersAuthAnonymous', true) === false) {
                throw new Exception('Anonymous authentication is disabled for this project', 501);
            }
            break;

        case 'invites':
            if($project->getAttribute('usersAuthInvites', true) === false) {
                throw new Exception('Invites authentication is disabled for this project', 501);
            }
            break;

        case 'jwt':
            if($project->getAttribute('usersAuthJWT', true) === false) {
                throw new Exception('JWT authentication is disabled for this project', 501);
            }
            break;
        
        default:
            throw new Exception('Unsupported authentication route');
            break;
    }

}, ['utopia', 'request', 'response', 'project', 'user'], 'auth');

App::shutdown(function ($utopia, $request, $response, $project, $events, $audits, $usage, $deletes, $mode) {
    /** @var Utopia\App $utopia */
    /** @var Utopia\Swoole\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Appwrite\Database\Document $project */
    /** @var Appwrite\Event\Event $events */
    /** @var Appwrite\Event\Event $audits */
    /** @var Appwrite\Event\Event $usage */
    /** @var Appwrite\Event\Event $deletes */
    /** @var Appwrite\Event\Event $functions */
    /** @var bool $mode */

    if (!empty($events->getParam('event'))) {
        if(empty($events->getParam('eventData'))) {
            $events->setParam('eventData', $response->getPayload());
        }

        $webhooks = clone $events;
        $functions = clone $events;

        $webhooks
            ->setQueue('v1-webhooks')
            ->setClass('WebhooksV1')
            ->trigger();

        $functions
            ->setQueue('v1-functions')
            ->setClass('FunctionsV1')
            ->trigger();
    }
    
    if (!empty($audits->getParam('event'))) {
        $audits->trigger();
    }
    
    if (!empty($deletes->getParam('type')) && !empty($deletes->getParam('document'))) {
        $deletes->trigger();
    }
    
    $route = $utopia->match($request);
    if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled' 
        && $project->getId()
        && $mode !== APP_MODE_ADMIN //TODO: add check to make sure user is admin
        && !empty($route->getLabel('sdk.namespace', null))) { // Don't calculate console usage on admin mode
        
        $usage
            ->setParam('networkRequestSize', $request->getSize() + $usage->getParam('storage'))
            ->setParam('networkResponseSize', $response->getSize())
            ->trigger()
        ;
    }

}, ['utopia', 'request', 'response', 'project', 'events', 'audits', 'usage', 'deletes', 'mode'], 'api');
