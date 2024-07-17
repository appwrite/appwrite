<?php

use Appwrite\Auth\Auth;

$guest = [
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
    'execution.write'
];

$member = array_merge($guest, [
    'account',
    'teams.read',
    'teams.write',
    'projects.read',
    'projects.write',
    'execution.read',
    'targets.read',
    'targets.write',
    'subscribers.read',
    'subscribers.write',
    'assistant.read'
]);

$billing = array_merge($guest, [
    'teams.read',
    'teams.write'
]);

$admin = [
    'global',
    'graphql',
    'sessions.write',
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
    'rules.read',
    'rules.write',
    'migrations.read',
    'migrations.write',
    'vcs.read',
    'vcs.write',
    'targets.read',
    'targets.write',
    'providers.write',
    'providers.read',
    'messages.write',
    'messages.read',
    'topics.write',
    'topics.read',
    'subscribers.write',
    'subscribers.read'
];

# Same as owner but without the ability to modify teams and projects
$developer = array_diff($admin, ['teams.write', 'projects.write']);

# Same as developer but without the ability to modify collections, buckets, topics, providers, migrations, rules, subscribers, and messages
$editor = array_diff($developer, [
    'collections.write',
    'buckets.write',
    'topics.write',
    'providers.write',
    'migrations.write',
    'rules.write',
    'subscribers.write',
    'messages.write'
]);

# Same as editor but without the ability to modify functions, execution, vcs, and webhooks
$analyst = array_diff($editor, [
    'databases.write',
    'functions.write',
    'execution.write',
    'vcs.write',
    'webhooks.write'
]);

return [
    Auth::USER_ROLE_GUESTS => [
        'label' => 'Guests',
        'scopes' => $guest,
    ],
    Auth::USER_ROLE_USERS => [
        'label' => 'Users',
        'scopes' => $member,
    ],
    Auth::USER_ROLE_ADMIN => [
        'label' => 'Admin',
        'scopes' => $admin,
    ],
    Auth::USER_ROLE_OWNER => [
        'label' => 'Owner',
        'scopes' => \array_merge($member, $admin),
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
    Auth::USER_ROLE_APPS => [
        'label' => 'Applications',
        'scopes' => ['global', 'health.read', 'graphql'],
    ],
];
