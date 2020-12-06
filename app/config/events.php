<?php

/**
 * List of publicly accessiable system events
 */
return [
    'account.create' => [
        'description' => 'This event triggers when the account is created.',
        'note' => '',
    ],
    'account.update.email' => [
        'description' => 'This event triggers when the account email address is updated.',
        'note' => '',
    ],
    'account.update.name' => [
        'description' => 'This event triggers when the account name is updated.',
        'note' => '',
    ],
    'account.update.password' => [
        'description' => 'This event triggers when the account password is updated.',
        'note' => '',
    ],
    'account.update.prefs' => [
        'description' => 'This event triggers when the account preferences are updated.',
        'note' => '',
    ],
    'account.recovery.create' => [
        'description' => 'This event triggers when the account recovery token is created.',
        'note' => 'version >= 0.7',
    ],
    'account.recovery.update' => [
        'description' => 'This event triggers when the account recovery token is validated.',
        'note' => 'version >= 0.7',
    ],
    'account.verification.create' => [
        'description' => 'This event triggers when the account verification token is created.',
        'note' => 'version >= 0.7',
    ],
    'account.verification.update' => [
        'description' => 'This event triggers when the account verification token is validated.',
        'note' => 'version >= 0.7',
    ],
    'account.delete' => [
        'description' => 'This event triggers when the account is deleted.',
        'note' => '',
    ],
    'account.sessions.create' => [
        'description' => 'This event triggers when the account session is created.',
        'note' => '',
    ],
    'account.sessions.delete' => [
        'description' => 'This event triggers when the account session is deleted.',
        'note' => '',
    ],
    'database.collections.create' => [
        'description' => 'This event triggers when a database collection is created.',
        'note' => '',
    ],
    'database.collections.update' => [
        'description' => 'This event triggers when a database collection is updated.',
        'note' => '',
    ],
    'database.collections.delete' => [
        'description' => 'This event triggers when a database collection is deleted.',
        'note' => '',
    ],
    'database.documents.create' => [
        'description' => 'This event triggers when a database document is created.',
        'note' => '',
    ],
    'database.documents.update' => [
        'description' => 'This event triggers when a database document is updated.',
        'note' => '',
    ],
    'database.documents.delete' => [
        'description' => 'This event triggers when a database document is deleted.',
        'note' => '',
    ],
    'storage.files.create' => [
        'description' => 'This event triggers when a storage file is created.',
        'note' => '',
    ],
    'storage.files.update' => [
        'description' => 'This event triggers when a storage file is updated.',
        'note' => '',
    ],
    'storage.files.delete' => [
        'description' => 'This event triggers when a storage file is deleted.',
        'note' => '',
    ],
    'users.create' => [
        'description' => 'This event triggers when a user is created from the users API.',
        'note' => 'version >= 0.7',
    ],
    'users.update.status' => [
        'description' => 'This event triggers when a user status is updated from the users API.',
        'note' => 'version >= 0.7',
    ],
    'users.delete' => [
        'description' => 'This event triggers when a user is deleted from users API.',
        'note' => 'version >= 0.7',
    ],
    'users.sessions.delete' => [
        'description' => 'This event triggers when a user session is deleted from users API.',
        'note' => 'version >= 0.7',
    ],
    'teams.create' => [
        'description' => 'This event triggers when a team is created.',
        'note' => 'version >= 0.7',
    ],
    'teams.update' => [
        'description' => 'This event triggers when a team is updated.',
        'note' => 'version >= 0.7',
    ],
    'teams.delete' => [
        'description' => 'This event triggers when a team is deleted.',
        'note' => 'version >= 0.7',
    ],
    'teams.memberships.create' => [
        'description' => 'This event triggers when a team memberships is created.',
        'note' => 'version >= 0.7',
    ],
    'teams.memberships.update.status' => [
        'description' => 'This event triggers when a team memberships status is updated.',
        'note' => 'version >= 0.7',
    ],
    'teams.memberships.delete' => [
        'description' => 'This event triggers when a team memberships is deleted.',
        'note' => 'version >= 0.7',
    ],
];