<?php

/**
 * List of publicly accessiable system events
 */
return [
    'account.create' => [
        'description' => 'This event triggers when the account is created.',
    ],
    'account.update.email' => [
        'description' => 'This event triggers when the account email address is updated.',
    ],
    'account.update.name' => [
        'description' => 'This event triggers when the account name is updated.',
    ],
    'account.update.password' => [
        'description' => 'This event triggers when the account password is updated.',
    ],
    'account.update.prefs' => [
        'description' => 'This event triggers when the account preferences are updated.',
    ],
    'account.recovery.create' => [
        'description' => 'This event triggers when the account recovery token is created.',
    ],
    'account.recovery.update' => [
        'description' => 'This event triggers when the account recovery token is validated.',
    ],
    'account.verification.create' => [
        'description' => 'This event triggers when the account verification token is created.',
    ],
    'account.verification.update' => [
        'description' => 'This event triggers when the account verification token is validated.',
    ],
    'account.delete' => [
        'description' => 'This event triggers when the account is deleted.',
    ],
    'account.sessions.create' => [
        'description' => 'This event triggers when the account session is created.',
    ],
    'account.sessions.delete' => [
        'description' => 'This event triggers when the account session is deleted.',
    ],
    'database.collections.create' => [
        'description' => 'This event triggers when a database collection is created.',
    ],
    'database.collections.update' => [
        'description' => 'This event triggers when a database collection is updated.',
    ],
    'database.collections.delete' => [
        'description' => 'This event triggers when a database collection is deleted.',
    ],
    'database.documents.create' => [
        'description' => 'This event triggers when a database document is created.',
    ],
    'database.documents.update' => [
        'description' => 'This event triggers when a database document is updated.',
    ],
    'database.documents.delete' => [
        'description' => 'This event triggers when a database document is deleted.',
    ],
    'storage.files.create' => [
        'description' => 'This event triggers when a storage file is created.',
    ],
    'storage.files.update' => [
        'description' => 'This event triggers when a storage file is updated.',
    ],
    'storage.files.delete' => [
        'description' => 'This event triggers when a storage file is deleted.',
    ],
    'users.create' => [
        'description' => 'This event triggers when a user is created from the users API.',
    ],
    'users.update.status' => [
        'description' => 'This event triggers when a user status is updated from the users API.',
    ],
    'users.delete' => [
        'description' => 'This event triggers when a user is deleted from users API.',
    ],
    'users.sessions.delete' => [
        'description' => 'This event triggers when a user session is deleted from users API.',
    ],
];