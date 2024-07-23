<?php

use Appwrite\Auth\Auth;

$apps = [
    'global',
    'health.read',
    'graphql',
];

$guests = [
    'global',
    'public',
    'home',
    'console',
    'graphql',
    'sessions.write',
    'documents.read',
    'documents.write',
    'files.read',
    'files.write',
    'locale.read',
    'avatars.read',
    'execution.write',
];

$member = [
    'global',
    'public',
    'home',
    'console',
    'graphql',
    'sessions.write',
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
    'execution.read',
    'execution.write',
    'targets.read',
    'targets.write',
    'subscribers.write',
    'subscribers.read',
    'assistant.read'
];

$analyst = array_merge($member, [
    'users.read',
    'databases.read',
    'collections.read',
    'buckets.read',
    'execution.read',
    'targets.read',
    'subscribers.read',
    'assistant.read',
    'functions.read',
    'platforms.read',
    'keys.read',
    'webhooks.read',
    'rules.read',
    'migrations.read',
    'vcs.read',
    'providers.read',
    'messages.read',
    'topics.read'
]);

$editor = array_merge($analyst, [
    'documents.write',
    'files.write',
    'execution.write',
    'targets.write',
    'subscribers.write',
]);

$developer = array_merge($editor, [
    'projects.write',
    'buckets.write',
    'users.write',
    'databases.write',
    'collections.write',
    'platforms.write',
    'keys.write',
    'webhooks.write',
    'functions.write',
    'rules.write',
    'migrations.write',
    'vcs.write',
    'targets.write',
    'providers.write',
    'messages.write',
    'topics.write',
]);

$owner = array_merge($developer, [
    'billing.read',
    'billing.write'
]);

$billing = array_merge($member, [
    'billing.read',
    'billing.write',
]);

return [
    Auth::USER_ROLE_APPS => [
        'label' => 'Applications',
        'scopes' => $apps,
    ],
    Auth::USER_ROLE_GUESTS => [
        'label' => 'Guests',
        'scopes' => $guests,
    ],
    Auth::USER_ROLE_USERS => [
        'label' => 'Users',
        'scopes' => $member,
    ],
    Auth::USER_ROLE_DEVELOPER => [
        'label' => 'Developer',
        'scopes' => $developer,
    ],
    Auth::USER_ROLE_EDITOR => [
        'label' => 'Editor',
        'scopes' => $editor,
    ],
    Auth::USER_ROLE_ANALYST => [
        'label' => 'Analyst',
        'scopes' => $analyst,
    ],
    Auth::USER_ROLE_BILLING => [
        'label' => 'Billing',
        'scopes' => $billing,
    ],
    Auth::USER_ROLE_OWNER => [
        'label' => 'Owner',
        'scopes' => $owner,
    ]
];
