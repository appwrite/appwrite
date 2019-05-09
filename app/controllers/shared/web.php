<?php

use Utopia\View;
use Utopia\Locale\Locale;

Locale::$exceptions = false;

$roles = [
    'owner' => Locale::getText('general.roles.owner'),
    'developer' => Locale::getText('general.roles.developer'),
    'admin' => Locale::getText('general.roles.admin'),
];

$layout = new View('../app/views/layouts/default.phtml');

/* AJAX check  */
if(!empty($request->getQuery('version', ''))) {
    $layout->setPath('../app/views/layouts/empty.phtml');
}

$layout
    ->setParam('title', APP_NAME)
    ->setParam('description', Locale::getText('general.description'))
    ->setParam('domain', $domain)
    ->setParam('api', $request->getServer('_APP_APPWRITE_HOST_CLIENT'))
    ->setParam('project', $request->getServer('_APP_APPWRITE_ID'))
    ->setParam('class', 'unknown')
    ->setParam('icon', '/images/favicon.png')
    ->setParam('roles', $roles)
    ->setParam('env', $utopia->getEnv())
;

$utopia->shutdown(function() use ($utopia, $response, $request, $layout, $version, $env) {
    $time = (60 * 60 * 24 * 45); // 45 days cache
    $isDev = (\Utopia\App::ENV_TYPE_DEVELOPMENT == $env);

    $response
        ->addHeader('Cache-Control', 'public, max-age=' . $time)
        ->addHeader('Expires', date('D, d M Y H:i:s', time() + $time) . ' GMT') // 45 days cache
        ->addHeader('X-UA-Compatible', 'IE=Edge'); // Deny IE browsers from going into quirks mode

    $route = $utopia->match($request);
    $scope = $route->getLabel('scope', '');
    $layout
        ->setParam('version', $version)
        ->setParam('isDev', $isDev)
        ->setParam('class', $scope)
    ;
});