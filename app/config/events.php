<?php

/**
 * List of publicly accessiable system events
 */

use Appwrite\Utopia\Response;

return [
    'account.create' => [
        'description' => 'This event triggers when the account is created.',
        'model' => Response::MODEL_USER,
        'note' => '',
    ],
    'account.update.email' => [
        'description' => 'This event triggers when the account email address is updated.',
        'model' => Response::MODEL_USER,
        'note' => '',
    ],
    'account.update.name' => [
        'description' => 'This event triggers when the account name is updated.',
        'model' => Response::MODEL_USER,
        'note' => '',
    ],
    'account.update.password' => [
        'description' => 'This event triggers when the account password is updated.',
        'model' => Response::MODEL_USER,
        'note' => '',
    ],
    'account.update.prefs' => [
        'description' => 'This event triggers when the account preferences are updated.',
        'model' => Response::MODEL_USER,
        'note' => '',
    ],
    'account.recovery.create' => [
        'description' => 'This event triggers when the account recovery token is created.',
        'model' => Response::MODEL_TOKEN,
        'note' => 'version >= 0.7',
    ],
    'account.recovery.update' => [
        'description' => 'This event triggers when the account recovery token is validated.',
        'model' => Response::MODEL_TOKEN,
        'note' => 'version >= 0.7',
    ],
    'account.verification.create' => [
        'description' => 'This event triggers when the account verification token is created.',
        'model' => Response::MODEL_TOKEN,
        'note' => 'version >= 0.7',
    ],
    'account.verification.update' => [
        'description' => 'This event triggers when the account verification token is validated.',
        'model' => Response::MODEL_TOKEN,
        'note' => 'version >= 0.7',
    ],
    'account.delete' => [
        'description' => 'This event triggers when the account is deleted.',
        'model' => Response::MODEL_USER,
        'note' => '',
    ],
    'account.sessions.create' => [
        'description' => 'This event triggers when the account session is created.',
        'model' => Response::MODEL_SESSION,
        'note' => '',
    ],
    'account.sessions.delete' => [
        'description' => 'This event triggers when the account session is deleted.',
        'model' => Response::MODEL_SESSION,
        'note' => '',
    ],
    'database.collections.create' => [
        'description' => 'This event triggers when a database collection is created.',
        'model' => Response::MODEL_COLLECTION,
        'note' => '',
    ],
    'database.collections.update' => [
        'description' => 'This event triggers when a database collection is updated.',
        'model' => Response::MODEL_COLLECTION,
        'note' => '',
    ],
    'database.collections.delete' => [
        'description' => 'This event triggers when a database collection is deleted.',
        'model' => Response::MODEL_COLLECTION,
        'note' => '',
    ],
    'database.documents.create' => [
        'description' => 'This event triggers when a database document is created.',
        'model' => Response::MODEL_ANY,
        'note' => '',
    ],
    'database.documents.update' => [
        'description' => 'This event triggers when a database document is updated.',
        'model' => Response::MODEL_ANY,
        'note' => '',
    ],
    'database.documents.delete' => [
        'description' => 'This event triggers when a database document is deleted.',
        'model' => Response::MODEL_ANY,
        'note' => '',
    ],
    'functions.create' => [
        'description' => 'This event triggers when a function is created.',
        'model' => Response::MODEL_FUNCTION,
        'note' => 'version >= 0.7',
    ],
    'functions.update' => [
        'description' => 'This event triggers when a function is updated.',
        'model' => Response::MODEL_FUNCTION,
        'note' => 'version >= 0.7',
    ],
    'functions.delete' => [
        'description' => 'This event triggers when a function is deleted.',
        'model' => Response::MODEL_ANY,
        'note' => 'version >= 0.7',
    ],
    'functions.tags.create' => [
        'description' => 'This event triggers when a function tag is created.',
        'model' => Response::MODEL_TAG,
        'note' => 'version >= 0.7',
    ],
    'functions.tags.update' => [
        'description' => 'This event triggers when a function tag is updated.',
        'model' => Response::MODEL_FUNCTION,
        'note' => 'version >= 0.7',
    ],
    'functions.tags.delete' => [
        'description' => 'This event triggers when a function tag is deleted.',
        'model' => Response::MODEL_ANY,
        'note' => 'version >= 0.7',
    ],
    'functions.executions.create' => [
        'description' => 'This event triggers when a function execution is created.',
        'model' => Response::MODEL_EXECUTION,
        'note' => 'version >= 0.7',
    ],
    'functions.executions.update' => [
        'description' => 'This event triggers when a function execution is updated.',
        'model' => Response::MODEL_EXECUTION,
        'note' => 'version >= 0.7',
    ],
    'storage.files.create' => [
        'description' => 'This event triggers when a storage file is created.',
        'model' => Response::MODEL_FILE,
        'note' => '',
    ],
    'storage.files.update' => [
        'description' => 'This event triggers when a storage file is updated.',
        'model' => Response::MODEL_FILE,
        'note' => '',
    ],
    'storage.files.delete' => [
        'description' => 'This event triggers when a storage file is deleted.',
        'model' => Response::MODEL_FILE,
        'note' => '',
    ],
    'users.create' => [
        'description' => 'This event triggers when a user is created from the users API.',
        'model' => Response::MODEL_USER,
        'note' => 'version >= 0.7',
    ],
    'users.update.prefs' => [
        'description' => 'This event triggers when a user preference is updated from the users API.',
        'model' => Response::MODEL_ANY,
        'note' => 'version >= 0.7',
    ],
    'users.update.status' => [
        'description' => 'This event triggers when a user status is updated from the users API.',
        'model' => Response::MODEL_USER,
        'note' => 'version >= 0.7',
    ],
    'users.delete' => [
        'description' => 'This event triggers when a user is deleted from users API.',
        'model' => Response::MODEL_USER,
        'note' => 'version >= 0.7',
    ],
    'users.sessions.delete' => [
        'description' => 'This event triggers when a user session is deleted from users API.',
        'model' => Response::MODEL_SESSION,
        'note' => 'version >= 0.7',
    ],
    'teams.create' => [
        'description' => 'This event triggers when a team is created.',
        'model' => Response::MODEL_TEAM,
        'note' => 'version >= 0.7',
    ],
    'teams.update' => [
        'description' => 'This event triggers when a team is updated.',
        'model' => Response::MODEL_TEAM,
        'note' => 'version >= 0.7',
    ],
    'teams.delete' => [
        'description' => 'This event triggers when a team is deleted.',
        'model' => Response::MODEL_TEAM,
        'note' => 'version >= 0.7',
    ],
    'teams.memberships.create' => [
        'description' => 'This event triggers when a team memberships is created.',
        'model' => Response::MODEL_MEMBERSHIP,
        'note' => 'version >= 0.7',
    ],
    'teams.memberships.update.status' => [
        'description' => 'This event triggers when a team memberships status is updated.',
        'model' => Response::MODEL_MEMBERSHIP,
        'note' => 'version >= 0.7',
    ],
    'teams.memberships.delete' => [
        'description' => 'This event triggers when a team memberships is deleted.',
        'model' => Response::MODEL_MEMBERSHIP,
        'note' => 'version >= 0.7',
    ],
];
