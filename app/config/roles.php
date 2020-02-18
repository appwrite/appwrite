<?php

const ROLE_GUEST = 0;
const ROLE_MEMBER = 1;
const ROLE_ADMIN = 2;
const ROLE_DEVELOPER = 3;
const ROLE_OWNER = 4;
const ROLE_APP = 5;
const ROLE_SYSTEM = 6;
const ROLE_ALL = '*';

$logged = [
    'public',
    'home',
    'console',
    'account',
    'teams.read',
    'teams.write',
    'documents.read',
    'documents.write',
    'files.read',
    'files.write',
    'projects.read',
    'projects.write',
    'locale.read',
    'avatars.read',
    'health.read',
];

$admins = [
    'teams.read',
    'teams.write',
    'documents.read',
    'documents.write',
    'files.read',
    'files.write',
    'users.read',
    'users.write',
    'collections.read',
    'collections.write',
    'platforms.read',
    'platforms.write',
    'keys.read',
    'keys.write',
    'tasks.read',
    'tasks.write',
    'webhooks.read',
    'webhooks.write',
    'locale.read',
    'avatars.read',
    'health.read',
];

return [
    ROLE_GUEST => [
        'label' => 'Guest',
        'scopes' => [
            'public',
            'home',
            'console',
            'files.read',
            'locale.read',
            'avatars.read',
            'health.read',
        ],
    ],
    ROLE_MEMBER => [
        'label' => 'Member',
        'scopes' => array_merge($logged, []),
    ],
    ROLE_ADMIN => [
        'label' => 'Admin',
        'scopes' => array_merge($admins, []),
    ],
    ROLE_DEVELOPER => [
        'label' => 'Developer',
        'scopes' => array_merge($admins, []),
    ],
    ROLE_OWNER => [
        'label' => 'Owner',
        'scopes' => array_merge($logged, $admins, []),
    ],
    ROLE_APP => [
        'label' => 'Application',
        'scopes' => ['health.read'],
    ],
];
