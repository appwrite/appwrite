<?php

use Utopia\View;
use Utopia\Config\Config;

$layout = new View(__DIR__.'/../../views/layouts/default.phtml');

$utopia->init(function () use ($utopia, $response, $request, $layout) {

    /* AJAX check  */
    if (!empty($request->getQuery('version', ''))) {
        $layout->setPath(__DIR__.'/../../views/layouts/empty.phtml');
    }
    $layout
        ->setParam('title', APP_NAME)
        ->setParam('protocol', Config::getParam('protocol'))
        ->setParam('domain', Config::getParam('domain'))
        ->setParam('home', $request->getServer('_APP_HOME'))
        ->setParam('setup', $request->getServer('_APP_SETUP'))
        ->setParam('class', 'unknown')
        ->setParam('icon', '/images/favicon.png')
        ->setParam('roles', [
            ['type' => 'owner', 'label' => 'Owner'],
            ['type' => 'developer', 'label' => 'Developer'],
            ['type' => 'admin', 'label' => 'Admin'],
        ])
        ->setParam('env', $utopia->getMode())
    ;

    $time = (60 * 60 * 24 * 45); // 45 days cache
    $isDev = (\Utopia\App::MODE_TYPE_DEVELOPMENT == Config::getParam('env'));

    $response
        ->addHeader('Cache-Control', 'public, max-age='.$time)
        ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time).' GMT') // 45 days cache
        ->addHeader('X-UA-Compatible', 'IE=Edge'); // Deny IE browsers from going into quirks mode

    $route = $utopia->match($request);
    $scope = $route->getLabel('scope', '');
    $layout
        ->setParam('version', Config::getParam('version'))
        ->setParam('isDev', $isDev)
        ->setParam('class', $scope)
    ;
}, 'web');
