<?php

use Utopia\View;
use Utopia\Locale\Locale;

global $protocol;

Locale::$exceptions = false;

$roles = [
    ['type' => 'owner', 'label' => 'Owner'],
    ['type' => 'developer', 'label' => 'Developer'],
    ['type' => 'admin', 'label' => 'Admin'],
];

$layout = new View(__DIR__.'/../../views/layouts/default.phtml');

/* AJAX check  */
if (!empty($request->getQuery('version', ''))) {
    $layout->setPath(__DIR__.'/../../views/layouts/empty.phtml');
}

$layout
    ->setParam('title', APP_NAME)
    ->setParam('protocol', $protocol)
    ->setParam('domain', $domain)
    ->setParam('home', $request->getServer('_APP_HOME'))
    ->setParam('setup', $request->getServer('_APP_SETUP'))
    ->setParam('class', 'unknown')
    ->setParam('icon', '/images/favicon.png')
    ->setParam('roles', $roles)
    ->setParam('env', $utopia->getEnv())
;

$utopia->shutdown(function () use ($utopia, $response, $request, $layout, $version, $env) {
    $time = (60 * 60 * 24 * 45); // 45 days cache
    $isDev = (\Utopia\App::ENV_TYPE_DEVELOPMENT == $env);

    $response
        ->addHeader('Cache-Control', 'public, max-age='.$time)
        ->addHeader('Expires', date('D, d M Y H:i:s', time() + $time).' GMT') // 45 days cache
        ->addHeader('X-UA-Compatible', 'IE=Edge'); // Deny IE browsers from going into quirks mode

    $route = $utopia->match($request);
    $scope = $route->getLabel('scope', '');
    $layout
        ->setParam('version', $version)
        ->setParam('isDev', $isDev)
        ->setParam('class', $scope)
    ;
});
