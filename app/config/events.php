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
    'database.documents.patch' => [
        'description' => 'This event triggers when a database document is patched.',
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
];