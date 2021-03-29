<?php

use Utopia\App;
use Utopia\System\System;

/**
 * List of Appwrite Cloud Functions supported environments
 */
$environments = [
    'node-14.5' => [
        'name' => 'Node.js',
        'version' => '14.5',
        'base' => 'node:14.5-alpine',
        'image' => 'appwrite/env-node-14.5:1.0.0',
        'build' => '/usr/src/code/docker/environments/node-14.5',
        'logo' => 'node.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'node-15.5' => [
        'name' => 'Node.js',
        'version' => '15.5',
        'base' => 'node:15.5-alpine',
        'image' => 'appwrite/env-node-15.5:1.0.0',
        'build' => '/usr/src/code/docker/environments/node-15.5',
        'logo' => 'node.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'php-7.4' => [
        'name' => 'PHP',
        'version' => '7.4',
        'base' => 'php:7.4-cli-alpine',
        'image' => 'appwrite/env-php-7.4:1.0.0',
        'build' => '/usr/src/code/docker/environments/php-7.4',
        'logo' => 'php.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'php-8.0' => [
        'name' => 'PHP',
        'version' => '8.0',
        'base' => 'php:8.0-cli-alpine',
        'image' => 'appwrite/env-php-8.0:1.0.0',
        'build' => '/usr/src/code/docker/environments/php-8.0',
        'logo' => 'php.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'ruby-2.7' => [
        'name' => 'Ruby',
        'version' => '2.7',
        'base' => 'ruby:2.7-alpine',
        'image' => 'appwrite/env-ruby-2.7:1.0.2',
        'build' => '/usr/src/code/docker/environments/ruby-2.7',
        'logo' => 'ruby.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'ruby-3.0' => [
        'name' => 'Ruby',
        'version' => '3.0',
        'base' => 'ruby:3.0-alpine',
        'image' => 'appwrite/env-ruby-3.0:1.0.0',
        'build' => '/usr/src/code/docker/environments/ruby-3.0',
        'logo' => 'ruby.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'python-3.8' => [
        'name' => 'Python',
        'version' => '3.8',
        'base' => 'python:3.8-alpine',
        'image' => 'appwrite/env-python-3.8:1.0.0',
        'build' => '/usr/src/code/docker/environments/python-3.8',
        'logo' => 'python.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'deno-1.2' => [
        'name' => 'Deno',
        'version' => '1.2',
        'base' => 'hayd/deno:alpine-1.2.0',
        'image' => 'appwrite/env-deno-1.2:1.0.0',
        'build' => '/usr/src/code/docker/environments/deno-1.2',
        'logo' => 'deno.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'deno-1.5' => [
        'name' => 'Deno',
        'version' => '1.5',
        'base' => 'hayd/deno:alpine-1.5.0',
        'image' => 'appwrite/env-deno-1.5:1.0.0',
        'build' => '/usr/src/code/docker/environments/deno-1.5',
        'logo' => 'deno.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'deno-1.6' => [
        'name' => 'Deno',
        'version' => '1.6',
        'base' => 'hayd/deno:alpine-1.6.0',
        'image' => 'appwrite/env-deno-1.6:1.0.0',
        'build' => '/usr/src/code/docker/environments/deno-1.6',
        'logo' => 'deno.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'deno-1.8' => [
        'name' => 'Deno',
        'version' => '1.8',
        'base' => 'hayd/deno:alpine-1.8.2',
        'image' => 'appwrite/env-deno-1.8:1.0.0',
        'build' => '/usr/src/code/docker/environments/deno-1.6',
        'logo' => 'deno.png',
        'supports' => [System::X86, System::PPC, System::ARM],
    ],
    'dart-2.10' => [
        'name' => 'Dart',
        'version' => '2.10',
        'base' => 'google/dart:2.10',
        'image' => 'appwrite/env-dart-2.10:1.0.0',
        'build' => '/usr/src/code/docker/environments/dart-2.10',
        'logo' => 'dart.png',
        'supports' => [System::X86],
    ],
    'dotnet-3.1' => [
        'name' => '.NET',
        'version' => '3.1',
        'base' => 'mcr.microsoft.com/dotnet/runtime:3.1-alpine',
        'image' => 'appwrite/env-dotnet-3.1:1.0.0',
        'build' => '/usr/src/code/docker/environments/dotnet-3.1',
        'logo' => 'dotnet.png',
        'supports' => [System::X86, System::ARM],
    ],
    'dotnet-5.0' => [
        'name' => '.NET',
        'version' => '5.0',
        'base' => 'mcr.microsoft.com/dotnet/runtime:5.0-alpine',
        'image' => 'appwrite/env-dotnet-5.0:1.0.0',
        'build' => '/usr/src/code/docker/environments/dotnet-5.0',
        'logo' => 'dotnet.png',
        'supports' => [System::X86, System::ARM],
    ],
];

$allowList = empty(App::getEnv('_APP_FUNCTIONS_ENVS', null)) ? false : \explode(',', App::getEnv('_APP_FUNCTIONS_ENVS', null));

$environments = array_filter($environments, function ($environment, $key) use ($allowList) {
    $isAllowed = $allowList && in_array($key, $allowList);
    $isSupported = in_array(System::getArchEnum(), $environment["supports"]);

    return $allowList ? ($isAllowed && $isSupported) : $isSupported;
}, ARRAY_FILTER_USE_BOTH);

return $environments;