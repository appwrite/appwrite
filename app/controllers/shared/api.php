<?php

use Utopia\Exception;
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit;
use Database\Database;

global $utopia, $request, $response, $register, $user, $project;

$utopia->init(function () use ($utopia, $request, $response, $register, $user, $project) {
    if (is_null($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS !== $project->getCollection()) {
        throw new Exception('Missing Project UID', 400);
    }

    $route = $utopia->match($request);

    /*
     * Abuse Check
     */
    $timeLimit = new TimeLimit($route->getLabel('abuse-key', 'url:{url},ip:{ip}'), $route->getLabel('abuse-limit', 0), $route->getLabel('abuse-time', 3600), function () use ($register) {
        return $register->get('db');
    });
    $timeLimit->setNamespace('app_'.$project->getUid());
    $timeLimit
        ->setParam('{userId}', $user->getUid())
        ->setParam('{userAgent}', $request->getServer('HTTP_USER_AGENT', ''))
        ->setParam('{ip}', $request->getIP())
        ->setParam('{url}', $request->getServer('HTTP_HOST', '').$route->getURL())
    ;

    //TODO make sure we get array here

    foreach ($request->getParams() as $key => $value) { // Set request params as potential abuse keys
        $timeLimit->setParam('{param-'.$key.'}', (is_array($value)) ? json_encode($value) : $value);
    }

    $abuse = new Abuse($timeLimit);

    if ($timeLimit->limit()) {
        $response
            ->addHeader('X-RateLimit-Limit', $timeLimit->limit())
            ->addHeader('X-RateLimit-Remaining', $timeLimit->remaining())
            ->addHeader('X-RateLimit-Reset', $timeLimit->time() + $route->getLabel('abuse-time', 3600))
        ;
    }

    if ($abuse->check() && $request->getServer('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled') {
        throw new Exception('Too many requests', 429);
    }
});