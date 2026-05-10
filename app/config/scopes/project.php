<?php

// List of publicly visible scopes
return [
    // Project
    "project.read" => [
        "description" =>
            "Access to read project\'s information",
        "category" => "Project",
    ],
    "project.write" => [
        "description" =>
            "Access to update project\'s information",
        "category" => "Project",
    ],
    "keys.read" => [
        "description" =>
            "Access to read project\'s keys",
        "category" => "Project",
    ],
    "keys.write" => [
        "description" =>
            "Access to create, update, and delete project\'s keys",
        "category" => "Project",
    ],
    "platforms.read" => [
        "description" =>
            "Access to read project\'s platforms",
        "category" => "Project",
    ],
    "platforms.write" => [
        "description" =>
            "Access to create, update, and delete project\'s platforms",
        "category" => "Project",
    ],
    "mocks.read" => [
        "description" =>
            "Access to read project\'s mocks",
        "category" => "Project",
    ],
    "mocks.write" => [
        "description" =>
            "Access to create, update, and delete project\'s mocks",
        "category" => "Project",
    ],
    "policies.read" => [
        "description" =>
            "Access to read project\'s policies. Replaced by \'project.policies.read\' for more granular control",
        "category" => "Project",
        'deprecated' => true,
    ],
    "policies.write" => [
        "description" =>
            "Access to update project\'s policies. Replaces by \'project.policies.write\' for more granular control",
        "category" => "Project",
        'deprecated' => true,
    ],
    "project.policies.read" => [
        "description" =>
            "Access to read project\'s policies",
        "category" => "Project",
    ],
    "project.policies.write" => [
        "description" =>
            "Access to update project\'s policies",
        "category" => "Project",
    ],
    "templates.read" => [
        "description" =>
            "Access to read project\'s templates",
        "category" => "Project",
    ],
    "templates.write" => [
        "description" =>
            "Access to create, update, and delete project\'s templates",
        "category" => "Project",
    ],
    "oauth2.read" => [
        "description" =>
            "Access to read project\'s OAuth2 configuration",
        "category" => "Project",
    ],
    "oauth2.write" => [
        "description" =>
            "Access to update project\'s OAuth2 configuration",
        "category" => "Project",
    ],

    // Auth
    'users.read' => [
        'description' => 'Access to read users',
        'category' => 'Auth',
    ],
    'users.write' => [
        'description' => 'Access to create, update, and delete users',
        'category' => 'Auth',
    ],
    'sessions.read' => [
        'description' => 'Access to read user sessions',
        'category' => 'Auth',
    ],
    'sessions.write' => [
        'description' => 'Access to create, update, and delete user sessions',
        'category' => 'Auth',
    ],
    'teams.read' => [
        'description' => 'Access to read teams',
        'category' => 'Auth',
    ],
    'teams.write' => [
        'description' => 'Access to create, update, and delete teams',
        'category' => 'Auth',
    ],

    // Databases
    'databases.read' => [
        'description' => 'Access to read databases',
        'category' => 'Databases',
    ],
    'databases.write' => [
        'description' => 'Access to create, update, and delete databases',
        'category' => 'Databases',
    ],
    'tables.read' => [
        'description' => 'Access to read database tables',
        'category' => 'Databases',
    ],
    'tables.write' => [
        'description' => 'Access to create, update, and delete database tables',
        'category' => 'Databases',
    ],
    'columns.read' => [
        'description' => 'Access to read database table columns',
        'category' => 'Databases',
    ],
    'columns.write' => [
        'description' => 'Access to create, update, and delete database table columns',
        'category' => 'Databases',
    ],
    'indexes.read' => [
        'description' => 'Access to read database table indexes',
        'category' => 'Databases',
    ],
    'indexes.write' => [
        'description' => 'Access to create, update, and delete database table indexes',
        'category' => 'Databases',
    ],
    'rows.read'  => [
        'description' => 'Access to read database table rows',
        'category' => 'Databases',
    ],
    'rows.write' => [
        'description' => 'Access to create, update, and delete database table rows',
        'category' => 'Databases',
    ],
    'collections.read' => [
        'description' => 'Access to read database collections',
        'category' => 'Databases',
        'deprecated' => true,
    ],
    'collections.write' => [
        'description' => 'Access to create, update, and delete database collections',
        'category' => 'Databases',
        'deprecated' => true,
    ],
    'attributes.read' => [
        'description' => 'Access to read database collection attributes',
        'category' => 'Databases',
        'deprecated' => true,
    ],
    'attributes.write' => [
        'description' => 'Access to create, update, and delete database collection attributes',
        'category' => 'Databases',
        'deprecated' => true,
    ],
    'documents.read'  => [
        'description' => 'Access to read database collection documents',
        'category' => 'Databases',
        'deprecated' => true,
    ],
    'documents.write' => [
        'description' => 'Access to create, update, and delete database collection documents',
        'category' => 'Databases',
        'deprecated' => true,
    ],

    // Storage
    'buckets.read' => [
        'description' => 'Access to read storage buckets',
        'category' => 'Storage',
    ],
    'buckets.write' => [
        'description' => 'Access to create, update, and delete storage buckets',
        'category' => 'Storage',
    ],
    'files.read' => [
        'description' => 'Access to read storage files and preview images',
        'category' => 'Storage',
    ],
    'files.write' => [
        'description' => 'Access to create, update, and delete storage files',
        'category' => 'Storage',
    ],
    'tokens.read' => [
        'description' => 'Access to read storage file tokens',
        'category' => 'Storage',
    ],
    'tokens.write' => [
        'description' => 'Access to create, update, and delete storage file tokens',
        'category' => 'Storage',
    ],

    // Functions
    'functions.read' => [
        'description' => 'Access to read functions and deployments',
        'category' => 'Functions',
    ],
    'functions.write' => [
        'description' => 'Access to create, update, and delete functions and deployments',
        'category' => 'Functions',
    ],
    'executions.read' => [
        'description' => 'Access to read function executions',
        'category' => 'Functions',
    ],
    'executions.write' => [
        'description' => 'Access to create function executions',
        'category' => 'Functions',
    ],
    'execution.read' => [
        'description' => 'Access to read function executions. This scope is deprecated for consistency purposes, and replaced by `executions.read`.',
        'category' => 'Functions',
        'deprecated' => true,
    ],
    'execution.write' => [
        'description' => 'Access to create function executions. This scope is deprecated for consistency purposes, and replaced by `executions.write`.',
        'category' => 'Functions',
        'deprecated' => true,
    ],

    // Sites
    'sites.read' => [
        'description' => 'Access to read sites and deployments',
        'category' => 'Sites',
    ],
    'sites.write' => [
        'description' => 'Access to create, update, and delete sites and deployments',
        'category' => 'Sites',
    ],
    'log.read' => [
        'description' => 'Access to read site logs',
        'category' => 'Sites',
    ],
    'log.write' => [
        'description' => 'Access to update, and delete site logs',
        'category' => 'Sites',
    ],

    // Messaging
    'providers.read' => [
        'description' => 'Access to read messaging providers',
        'category' => 'Messaging',
    ],
    'providers.write' => [
        'description' => 'Access to create, update, and delete messaging providers',
        'category' => 'Messaging',
    ],
    'topics.read' => [
        'description' => 'Access to read messaging topics',
        'category' => 'Messaging',
    ],
    'topics.write' => [
        'description' => 'Access to create, update, and delete messaging topics',
        'category' => 'Messaging',
    ],
    'subscribers.read' => [
        'description' => 'Access to read messaging subscribers',
        'category' => 'Messaging',
    ],
    'subscribers.write' => [
        'description' => 'Access to create, update, and delete messaging subscribers',
        'category' => 'Messaging',
    ],
    'targets.read' => [
        'description' => 'Access to read messaging targets',
        'category' => 'Messaging',
    ],
    'targets.write' => [
        'description' => 'Access to create, update, and delete messaging targets',
        'category' => 'Messaging',
    ],
    'messages.read' => [
        'description' => 'Access to read messaging messages',
        'category' => 'Messaging',
    ],
    'messages.write' => [
        'description' => 'Access to create, update, and delete messaging messages',
        'category' => 'Messaging',
    ],

    // Proxy
    'rules.read' => [
        'description' => 'Access to read proxy rules.',
        'category' => 'Proxy',
    ],
    'rules.write' => [
        'description' => 'Access to create, update, and delete proxy rules.',
        'category' => 'Proxy',
    ],

    // Other
    "webhooks.read" => [
        "description" =>
            "Access to read webhooks",
        'category' => 'Other',
    ],
    "webhooks.write" => [
        "description" =>
            "Access to create, update, and delete webhooks",
        'category' => 'Other',
    ],
    'locale.read' => [
        'description' => 'Access to use Locale service',
        'category' => 'Other',
    ],
    'avatars.read' => [
        'description' => 'Access to use Avatars service',
        'category' => 'Other',
    ],
    'health.read' => [
        'description' => 'Access to use Health service',
        'category' => 'Other',
    ],
    'assistant.read' => [
        'description' => 'Access to use Assistant service',
        'category' => 'Other',
    ],
    'migrations.read' => [
        'description' => 'Access to read migrations',
        'category' => 'Other',
    ],
    'migrations.write' => [
        'description' => 'Access to create, update, and delete migrations.',
        'category' => 'Other',
    ],

    // TODO: Figure out where to move those
    'schedules.read' => [
        'description' => 'Access to read schedules.',
        'category' => 'Other',
    ],
    'schedules.write' => [
        'description' => 'Access to create, update, and delete schedules.',
        'category' => 'Other',
    ],
    'vcs.read' => [
        'description' => 'Access to read resources under VCS service.',
        'category' => 'Other',
    ],
    'vcs.write' => [
        'description' => 'Access to create, update, and delete resources under VCS service.',
        'category' => 'Other',
    ],
];
