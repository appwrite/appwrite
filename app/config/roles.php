<?php

use Appwrite\Auth\Auth;

$member = [
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
    'project.read',
    'project.write',
    'admin.read',
    'admin.write',
    'locale.read',
    'avatars.read',
    'execution.read',
    'execution.write',
];

$admins = [
    'teams.read',
    'teams.write',
    'documents.read',
    'documents.write',
    'files.read',
    'files.write',
    'buckets.read',
    'buckets.write',
    'users.read',
    'users.write',
    'databases.read',
    'databases.write',
    'collections.read',
    'collections.write',
    'platforms.read',
    'platforms.write',
    'keys.read',
    'keys.write',
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
    Auth::USER_ROLE_GUESTS => [
        'label' => 'Guests',
        'scopes' => [
            'public',
            'home',
            'console',
            'documents.read',
            'documents.write',
            'files.read',
            'files.write',
            'locale.read',
            'avatars.read',
            'execution.write',
        ],
    ],
    Auth::USER_ROLE_USERS => [
        'label' => 'Users',
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
    Auth::USER_ROLE_APPS => [
        'label' => 'Applications',
        'scopes' => ['health.read'],
    ],
];
