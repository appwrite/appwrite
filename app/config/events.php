<?php

/**
 * List of publicly accessible system events
 */

use Appwrite\Utopia\Response;

return [
    'users' => [
        '$model' => Response::MODEL_USER,
        '$resource' => true,
        '$description' => 'This event triggers on any user\'s event.',
        'sessions' => [
            '$model' => Response::MODEL_SESSION,
            '$resource' => true,
            '$description' => 'This event triggers on any user\'s sessions event.',
            'create' => [
                '$description' => 'This event triggers when a session for a user is created.',
            ],
            'delete' => [
                '$description' => 'This event triggers when a session for a user is deleted.'
            ],
        ],
        'recovery' => [
            '$model' => Response::MODEL_TOKEN,
            '$resource' => true,
            '$description' => 'This event triggers on any user\'s recovery token event.',
            'create' => [
                '$description' => 'This event triggers when a recovery token for a user is created.',
            ],
            'update' => [
                '$description' => 'This event triggers when a recovery token for a user is validated.'
            ],
        ],
        'verification' => [
            '$model' => Response::MODEL_TOKEN,
            '$resource' => true,
            '$description' => 'This event triggers on any user\'s verification token event.',
            'create' => [
                '$description' => 'This event triggers when a verification token for a user is created.',
            ],
            'update' => [
                '$description' => 'This event triggers when a verification token for a user is validated.'
            ],
        ],
        'create' => [
            '$description' => 'This event triggers when a user is created.'
        ],
        'delete' => [
            '$description' => 'This event triggers when a user is deleted.',
        ],
        'update' => [
            '$description' => 'This event triggers when a user is updated.',
            'email' => [
                '$description' => 'This event triggers when a user\'s email address is updated.',
            ],
            'name' => [
                '$description' => 'This event triggers when a user\'s name is updated.',
            ],
            'password' => [
                '$description' => 'This event triggers when a user\'s password is updated.',
            ],
            'status' => [
                '$description' => 'This event triggers when a user\'s status is updated.',
            ],
            'prefs' => [
                '$description' => 'This event triggers when a user\'s preferences is updated.',
            ],
        ]
    ],
    'databases' => [
        '$model' => Response::MODEL_DATABASE,
        '$resource' => true,
        '$description' => 'This event triggers on any database event.',
        'collections' => [
            '$model' => Response::MODEL_COLLECTION,
            '$resource' => true,
            '$description' => 'This event triggers on any collection event.',
            'documents' => [
                '$model' => Response::MODEL_DOCUMENT,
                '$resource' => true,
                '$description' => 'This event triggers on any documents event.',
                'create' => [
                    '$description' => 'This event triggers when a document is created.',
                ],
                'delete' => [
                    '$description' => 'This event triggers when a document is deleted.'
                ],
                'update' => [
                    '$description' => 'This event triggers when a document is updated.'
                ],
            ],
            'indexes' => [
                '$model' => Response::MODEL_INDEX,
                '$resource' => true,
                '$description' => 'This event triggers on any indexes event.',
                'create' => [
                    '$description' => 'This event triggers when an index is created.',
                ],
                'delete' => [
                    '$description' => 'This event triggers when an index is deleted.'
                ]
            ],
            'attributes' => [
                '$model' => Response::MODEL_ATTRIBUTE,
                '$resource' => true,
                '$description' => 'This event triggers on any attributes event.',
                'create' => [
                    '$description' => 'This event triggers when an attribute is created.',
                ],
                'delete' => [
                    '$description' => 'This event triggers when an attribute is deleted.'
                ]
            ],
            'create' => [
                '$description' => 'This event triggers when a collection is created.'
            ],
            'delete' => [
                '$description' => 'This event triggers when a collection is deleted.',
            ],
            'update' => [
                '$description' => 'This event triggers when a collection is updated.',
            ]
        ],
        'create' => [
            '$description' => 'This event triggers when a database is created.'
        ],
        'delete' => [
            '$description' => 'This event triggers when a database is deleted.',
        ],
        'update' => [
            '$description' => 'This event triggers when a database is updated.',
        ]
    ],
    'buckets' => [
        '$model' => Response::MODEL_BUCKET,
        '$resource' => true,
        '$description' => 'This event triggers on any buckets event.',
        'files' => [
            '$model' => Response::MODEL_FILE,
            '$resource' => true,
            '$description' => 'This event triggers on any files event.',
            'create' => [
                '$description' => 'This event triggers when a file is created.',
            ],
            'delete' => [
                '$description' => 'This event triggers when a file is deleted.'
            ],
            'update' => [
                '$description' => 'This event triggers when a file is updated.'
            ],
        ],
        'create' => [
            '$description' => 'This event triggers when a bucket is created.'
        ],
        'delete' => [
            '$description' => 'This event triggers when a bucket is deleted.',
        ],
        'update' => [
            '$description' => 'This event triggers when a bucket is updated.',
        ]
    ],
    'teams' => [
        '$model' => Response::MODEL_TEAM,
        '$resource' => true,
        '$description' => 'This event triggers on any teams event.',
        'memberships' => [
            '$model' => Response::MODEL_MEMBERSHIP,
            '$resource' => true,
            '$description' => 'This event triggers on any team memberships event.',
            'create' => [
                '$description' => 'This event triggers when a membership is created.',
            ],
            'delete' => [
                '$description' => 'This event triggers when a membership is deleted.'
            ],
            'update' => [
                '$description' => 'This event triggers when a membership is updated.',
                'status' => [
                    '$description' => 'This event triggers when a team memberships status is updated.'
                ]
            ],
        ],
        'create' => [
            '$description' => 'This event triggers when a team is created.'
        ],
        'delete' => [
            '$description' => 'This event triggers when a team is deleted.',
        ],
        'update' => [
            '$description' => 'This event triggers when a team is updated.',
            'prefs' => [
                '$description' => 'This event triggers when a team\'s preferences are updated.',
            ],
        ]
    ],
    'functions' => [
        '$model' => Response::MODEL_FUNCTION,
        '$resource' => true,
        '$description' => 'This event triggers on any functions event.',
        'deployments' => [
            '$model' => Response::MODEL_DEPLOYMENT,
            '$resource' => true,
            '$description' => 'This event triggers on any deployments event.',
            'create' => [
                '$description' => 'This event triggers when a deployment is created.',
            ],
            'delete' => [
                '$description' => 'This event triggers when a deployment is deleted.'
            ],
            'update' => [
                '$description' => 'This event triggers when a deployment is updated.'
            ],
        ],
        'executions' => [
            '$model' => Response::MODEL_EXECUTION,
            '$resource' => true,
            '$description' => 'This event triggers on any executions event.',
            'create' => [
                '$description' => 'This event triggers when an execution is created.',
            ],
            'delete' => [
                '$description' => 'This event triggers when an execution is deleted.'
            ],
            'update' => [
                '$description' => 'This event triggers when an execution is updated.'
            ],
        ],
        'create' => [
            '$description' => 'This event triggers when a function is created.'
        ],
        'delete' => [
            '$description' => 'This event triggers when a function is deleted.',
        ],
        'update' => [
            '$description' => 'This event triggers when a function is updated.',
        ]
    ],
    'rules' => [
        '$model' => Response::MODEL_PROXY_RULE,
        '$resource' => true,
        '$description' => 'This event triggers on any proxy rule event.',
        'create' => [
            '$description' => 'This event triggers when a proxy rule is created.'
        ],
        'delete' => [
            '$description' => 'This event triggers when a proxy rule is deleted.',
        ],
        'update' => [
            '$description' => 'This event triggers when a proxy rule is updated.',
        ]
    ]
];
