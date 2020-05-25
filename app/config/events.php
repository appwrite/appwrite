<?php

/**
 * List of publicly accessiable system events
 */
return [
    'account.create' => [
        'description' => 'Triggers any time a new user register an account.',
    ],
    'account.update.email' => [
        'description' => 'Triggers any time a a user updates his or her acoount email address.',
    ],
    'account.update.name' => [
        'description' => 'Triggers any time a a user updates his or her acoount name.',
    ],
    'account.update.password' => [
        'description' => 'Triggers any time a a user updates his or her acoount password.',
    ],
    'account.update.prefs' => [
        'description' => 'Triggers any time a a user updates his or her acoount preferences.',
    ],
    'account.delete' => [
        'description' => 'Triggers any time a new user is deleting its account.',
    ],
    'account.sessions.create' => [
        'description' => 'Triggers any time a user session is being created.',
    ],
    'account.sessions.delete' => [
        'description' => 'Triggers any time a user session is being deleted.',
    ],
    'database.collections.create' => [
        'description' => 'Triggers any time a new database collection is being created.',
    ],
    'database.collections.update' => [
        'description' => 'Triggers any time a new database collection is being updated.',
    ],
    'database.collections.delete' => [
        'description' => 'Triggers any time a database collection is being deleted.',
    ],
    'database.documents.create' => [
        'description' => 'Triggers any time a new database document is being created.',
    ],
    'database.documents.patch' => [
        'description' => 'Triggers any time a new database document is being updated.',
    ],
    'database.documents.delete' => [
        'description' => 'Triggers any time a database document is being deleted.',
    ],
    'storage.files.create' => [
        'description' => 'Triggers any time a storage file has been created.',
    ],
    'storage.files.update' => [
        'description' => 'Triggers any time a storage file has been updated.',
    ],
    'storage.files.delete' => [
        'description' => 'Triggers any time a file has been deleted.',
    ],
];