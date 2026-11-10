<?php

use Appwrite\Utopia\Database\Documents\User;

/**
 * What a monitor can check.
 *
 * `types` are the kinds of check a monitor runs, each with its own create and
 * update routes under `/v1/monitors/<type>`; `usage` reads the project's usage
 * stats. `optOut` lets a recipient stop every alert email of the type at once;
 * without it, an email offers to stop only its own monitor's. The rest describes usage monitors.
 *
 * `resources` are what a usage monitor's `resourceType` rule can scope it to,
 * each with the name alert emails give the type, the project collection its ID
 * is looked up in (`project` needs none, it is the project itself) and, for a
 * resource whose usage is recorded under an engine's name, the attribute
 * holding that engine.
 *
 * `metrics` are keyed by the usage metric name the project's usage charts
 * read. Each has the name notifications show, the unit its threshold is
 * written in and its `kind`: a `counter` is summed over the monitor's window,
 * a `gauge` is its latest sample, and `growth` is how much a gauge rose over
 * the window. `resources` maps each resource type
 * the metric can be scoped to onto where its writer records it:
 *
 * - `metric`: the series the value is stored under, when not the metric's own name
 * - `resourceType`: the `resourceType` the writer records for this scope; omitted, rows are not filtered by it
 * - `id`: the dimension holding the watched resource's ID, `resourceId` or `resourceInternalId`
 * - `engines`: the series is also recorded under each of `engines` as `<engine>.<metric>`;
 *   a project reads them all, one resource reads its own engine's
 * - `sum`: a list of such series added together, in place of the keys above
 *
 * `roles` are the organization roles a monitor's `notify` can name: the
 * confirmed members holding any of them get its email and console notification.
 *
 * An edition adds types, resources, metrics and roles here, and a meter of its own
 * for metrics that are not read from the project's usage.
 *
 * `Appwrite\Alert\Meter` reads each metric listed here.
 */

return [
    'roles' => [User::ROLE_OWNER, User::ROLE_DEVELOPER],
    'types' => [
        'usage' => [
            'label' => 'Usage',
            'optOut' => true,
        ],
    ],
    'resources' => [
        'project' => [
            'label' => 'Project',
            'collection' => null,
        ],
        'database' => [
            'label' => 'Database',
            'collection' => 'databases',
            'engine' => 'type',
        ],
        'bucket' => [
            'label' => 'Bucket',
            'collection' => 'buckets',
        ],
        'function' => [
            'label' => 'Function',
            'collection' => 'functions',
        ],
        'site' => [
            'label' => 'Site',
            'collection' => 'sites',
        ],
    ],
    'engines' => [DATABASE_TYPE_DOCUMENTSDB, DATABASE_TYPE_VECTORSDB],
    'metrics' => [
        METRIC_NETWORK_REQUESTS => [
            'label' => 'Requests',
            'kind' => 'counter',
            'unit' => 'requests',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_NETWORK_INBOUND => [
            'label' => 'Inbound bandwidth',
            'kind' => 'counter',
            'unit' => 'bytes',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_NETWORK_OUTBOUND => [
            'label' => 'Outbound bandwidth',
            'kind' => 'counter',
            'unit' => 'bytes',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_EXECUTIONS => [
            'label' => 'Executions',
            'kind' => 'counter',
            'unit' => 'executions',
            'resources' => [
                'project' => [],
                'function' => ['resourceType' => 'function', 'id' => 'resourceId'],
                'site' => ['resourceType' => 'site', 'id' => 'resourceId'],
            ],
        ],
        METRIC_EXECUTIONS_MB_SECONDS => [
            'label' => 'Execution GB-hours',
            'kind' => 'counter',
            'unit' => 'mbSeconds',
            'resources' => [
                'project' => [],
                'function' => ['resourceType' => 'function', 'id' => 'resourceId'],
                'site' => ['resourceType' => 'site', 'id' => 'resourceId'],
            ],
        ],
        METRIC_REALTIME_CONNECTIONS => [
            'label' => 'Concurrent connections',
            // Sampled by the stats-resources task from the open/close deltas;
            // the samples carry no resource tags.
            'kind' => 'gauge',
            'unit' => 'connections',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_WEBHOOKS_SENT => [
            'label' => 'Webhook events sent',
            'kind' => 'counter',
            'unit' => 'events',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_WEBHOOKS_FAILED => [
            'label' => 'Webhook events failed',
            'kind' => 'counter',
            'unit' => 'events',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_MAU => [
            'label' => 'Monthly active users',
            // The resource sweep writes it with no resource tags.
            'kind' => 'gauge',
            'unit' => 'users',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_AUTH_METHOD_PHONE => [
            'label' => 'OTP attempts',
            'kind' => 'counter',
            'unit' => 'attempts',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_USERS => [
            'label' => 'Sign-ups',
            // Net growth of the users count, as the sign-ups chart shows it.
            'kind' => 'growth',
            'unit' => 'users',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_DATABASES_OPERATIONS_READS => [
            'label' => 'Database reads',
            'kind' => 'counter',
            'unit' => 'operations',
            'resources' => [
                'project' => ['engines' => true],
                'database' => ['engines' => true, 'resourceType' => 'database', 'id' => 'resourceId'],
            ],
        ],
        METRIC_DATABASES_OPERATIONS_WRITES => [
            'label' => 'Database writes',
            'kind' => 'counter',
            'unit' => 'operations',
            'resources' => [
                'project' => ['engines' => true],
                'database' => ['engines' => true, 'resourceType' => 'database', 'id' => 'resourceId'],
            ],
        ],
        METRIC_DATABASES_STORAGE => [
            'label' => 'Database storage',
            'kind' => 'gauge',
            'unit' => 'bytes',
            'resources' => [
                'project' => ['engines' => true, 'resourceType' => 'project'],
                'database' => ['engines' => true, 'resourceType' => 'database', 'id' => 'resourceId'],
            ],
        ],
        METRIC_STORAGE => [
            'label' => 'Storage used',
            'kind' => 'gauge',
            'unit' => 'bytes',
            'resources' => [
                // The resource sweep writes `storage` per resource; a project's
                // is the totals of the resource families the storage chart shows.
                'project' => ['sum' => [
                    ['metric' => METRIC_FILES_STORAGE, 'resourceType' => 'project'],
                    ['metric' => METRIC_DATABASES_STORAGE, 'engines' => true, 'resourceType' => 'project'],
                    ['metric' => METRIC_DEPLOYMENTS_STORAGE, 'resourceType' => 'deployment'],
                    ['metric' => METRIC_BUILDS_STORAGE, 'resourceType' => 'build'],
                ]],
                'bucket' => ['resourceType' => 'bucket', 'id' => 'resourceId'],
                'database' => ['resourceType' => 'database', 'id' => 'resourceId'],
                'function' => ['resourceType' => 'function', 'id' => 'resourceId'],
                'site' => ['resourceType' => 'site', 'id' => 'resourceId'],
            ],
        ],
        METRIC_FILES_STORAGE => [
            'label' => 'File storage',
            'kind' => 'gauge',
            'unit' => 'bytes',
            'resources' => [
                'project' => ['resourceType' => 'project'],
            ],
        ],
        METRIC_MESSAGES_SENT => [
            'label' => 'Messages sent',
            'kind' => 'counter',
            'unit' => 'messages',
            'resources' => [
                'project' => [],
            ],
        ],
        \str_replace('{type}', MESSAGE_TYPE_SMS, METRIC_MESSAGES_TYPE_SENT) => [
            'label' => 'SMS messages sent',
            'kind' => 'counter',
            'unit' => 'messages',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_MESSAGES_FAILED => [
            'label' => 'Messages failed',
            'kind' => 'counter',
            'unit' => 'messages',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_AVATARS_SCREENSHOTS_GENERATED => [
            'label' => 'Screenshots generated',
            'kind' => 'counter',
            'unit' => 'screenshots',
            'resources' => [
                'project' => [],
            ],
        ],
    ],
];
