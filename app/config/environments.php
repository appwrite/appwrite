<?php

return [
    'node-14' => [
        'name' => 'Node.js',
        'version' => '14.5',
        'base' => 'node:14.5-alpine',
        'image' => 'appwrite/env-node:14.5',
        'logo' => 'node.png',
    ],
    'php-7.4' => [
        'name' => 'PHP',
        'version' => '7.4',
        'base' => 'php:7.4-cli-alpine',
        'image' => 'appwrite/env-php:7.4',
        'logo' => 'php.png',
    ],
    'ruby-2.7' => [
        'name' => 'Ruby',
        'version' => '2.7',
        'base' => 'ruby:2.7-alpine',
        'image' => 'appwrite/ruby-node:2.7',
        'logo' => 'ruby.png',
    ],
    'python-3.8' => [
        'name' => 'Python',
        'version' => '3.8',
        'base' => 'python:3.8-alpine',
        'image' => 'appwrite/env-python:3.8',
        'logo' => 'python.png',
    ],
    // 'dart-2.8' => [
    //     'name' => 'Dart',
    //     'version' => '2.8',
    //     'base' => 'google/dart:2.8',
    //     'image' => 'appwrite/env-dart:2.8',
    //     'logo' => 'dart.png',
    // ],
];