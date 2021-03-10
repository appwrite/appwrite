<?php

use Appwrite\Auth\Auth;

$member = [
    'public',
    'home',
    'console',
    'account',
    'graphql',
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
    'execution.read',
    'execution.write',
];

$admins = [
    'graphql',
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
    'functions.read',
    'functions.write',
    'execution.read',
    'execution.write',
];

return [
    Auth::USER_ROLE_GUEST => [
        'label' => 'Guest',
        'scopes' => [
            'public',
            'home',
            'console',
            'graphql',
            'documents.read',
            'files.read',
            'locale.read',
            'avatars.read',
            'execution.read',
            'execution.write',
        ],
    ],
    Auth::USER_ROLE_MEMBER => [
        'label' => 'Member',
        'scopes' => \array_merge($member, []),
    ],
    Auth::USER_ROLE_ADMIN => [
        'label' => 'Admin',
        'scopes' => \array_merge($admins, []),
    ],
    Auth::USER_ROLE_DEVELOPER => [
        'label' => 'Developer',
        'scopes' => \array_merge($admins, []),
    ],
    Auth::USER_ROLE_OWNER => [
        'label' => 'Owner',
        'scopes' => \array_merge($member, $admins, []),
    ],
    Auth::USER_ROLE_APP => [
        'label' => 'Application',
        'scopes' => ['health.read', 'graphql'],
    ],
];
