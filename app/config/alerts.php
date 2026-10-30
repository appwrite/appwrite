<?php

/**
 * What a monitor can check.
 *
 * `types` are the kinds of check a monitor runs, each with its own create and
 * update routes under `/v1/monitors/<type>`; `usage` reads the project's usage
 * stats. The rest describes usage monitors.
 *
 * `resources` are what a usage monitor's `resourceType` rule can scope it to,
 * each with the project collection its ID is looked up in (`project` needs
 * none, it is the project itself) and, for a resource whose usage is recorded
 * under an engine's name, the attribute holding that engine.
 *
 * `metrics` are keyed by the usage metric name the project's usage charts
 * read. Each has the name notifications show, its `group` on the usage page,
 * the unit its threshold is written in and its `kind`: a `counter` is summed
 * over the monitor's window, a `gauge` is its latest sample, and `growth` is
 * how much a gauge rose over the window. `resources` maps each resource type
 * the metric can be scoped to onto where its writer records it:
 *
 * - `metric`: the series the value is stored under, when not the metric's own name
 * - `resourceType`: the `resourceType` the writer records for this scope; omitted, rows are not filtered by it
 * - `id`: the dimension holding the watched resource's ID, `resourceId` or `resourceInternalId`
 * - `engines`: the series is also recorded under each of `engines` as `<engine>.<metric>`;
 *   a project reads them all, one resource reads its own engine's
 * - `sum`: a list of such series added together, in place of the keys above
 *
 * An edition adds types, resources and metrics here, and a meter of its own
 * for metrics that are not read from the project's usage.
 *
 * `Appwrite\Alert\Meter` reads each metric listed here.
 */

return [
    'types' => [
        'usage' => [
            'label' => 'Usage',
        ],
    ],
    'resources' => [
        'project' => [
            'collection' => null,
        ],
        'database' => [
            'collection' => 'databases',
            'engine' => 'type',
        ],
        'bucket' => [
            'collection' => 'buckets',
        ],
        'function' => [
            'collection' => 'functions',
        ],
        'site' => [
            'collection' => 'sites',
        ],
    ],
    'engines' => [DATABASE_TYPE_DOCUMENTSDB, DATABASE_TYPE_VECTORSDB],
    'metrics' => [
        METRIC_NETWORK_REQUESTS => [
            'label' => 'Requests',
            'group' => 'network',
            'kind' => 'counter',
            'unit' => 'requests',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_NETWORK_INBOUND => [
            'label' => 'Inbound bandwidth',
            'group' => 'network',
            'kind' => 'counter',
            'unit' => 'bytes',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_NETWORK_OUTBOUND => [
            'label' => 'Outbound bandwidth',
            'group' => 'network',
            'kind' => 'counter',
            'unit' => 'bytes',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_EXECUTIONS => [
            'label' => 'Executions',
            'group' => 'compute',
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
            'group' => 'compute',
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
            'group' => 'realtime',
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
            'group' => 'webhooks',
            'kind' => 'counter',
            'unit' => 'events',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_WEBHOOKS_FAILED => [
            'label' => 'Webhook events failed',
            'group' => 'webhooks',
            'kind' => 'counter',
            'unit' => 'events',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_MAU => [
            'label' => 'Monthly active users',
            'group' => 'auth',
            // The resource sweep writes it with no resource tags.
            'kind' => 'gauge',
            'unit' => 'users',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_AUTH_METHOD_PHONE => [
            'label' => 'OTP attempts',
            'group' => 'auth',
            'kind' => 'counter',
            'unit' => 'attempts',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_USERS => [
            'label' => 'Sign-ups',
            'group' => 'auth',
            // Net growth of the users count, as the sign-ups chart shows it.
            'kind' => 'growth',
            'unit' => 'users',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_DATABASES_OPERATIONS_READS => [
            'label' => 'Database reads',
            'group' => 'databases',
            'kind' => 'counter',
            'unit' => 'operations',
            'resources' => [
                'project' => ['engines' => true],
                'database' => ['engines' => true, 'resourceType' => 'database', 'id' => 'resourceId'],
            ],
        ],
        METRIC_DATABASES_OPERATIONS_WRITES => [
            'label' => 'Database writes',
            'group' => 'databases',
            'kind' => 'counter',
            'unit' => 'operations',
            'resources' => [
                'project' => ['engines' => true],
                'database' => ['engines' => true, 'resourceType' => 'database', 'id' => 'resourceId'],
            ],
        ],
        METRIC_DATABASES_STORAGE => [
            'label' => 'Database storage',
            'group' => 'databases',
            'kind' => 'gauge',
            'unit' => 'bytes',
            'resources' => [
                'project' => ['engines' => true, 'resourceType' => 'project'],
                'database' => ['engines' => true, 'resourceType' => 'database', 'id' => 'resourceId'],
            ],
        ],
        METRIC_STORAGE => [
            'label' => 'Storage used',
            'group' => 'storage',
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
            'group' => 'storage',
            'kind' => 'gauge',
            'unit' => 'bytes',
            'resources' => [
                'project' => ['resourceType' => 'project'],
            ],
        ],
        METRIC_MESSAGES_SENT => [
            'label' => 'Messages sent',
            'group' => 'messaging',
            'kind' => 'counter',
            'unit' => 'messages',
            'resources' => [
                'project' => [],
            ],
        ],
        \str_replace('{type}', MESSAGE_TYPE_SMS, METRIC_MESSAGES_TYPE_SENT) => [
            'label' => 'SMS messages sent',
            'group' => 'messaging',
            'kind' => 'counter',
            'unit' => 'messages',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_MESSAGES_FAILED => [
            'label' => 'Messages failed',
            'group' => 'messaging',
            'kind' => 'counter',
            'unit' => 'messages',
            'resources' => [
                'project' => [],
            ],
        ],
        METRIC_AVATARS_SCREENSHOTS_GENERATED => [
            'label' => 'Screenshots generated',
            'group' => 'avatars',
            'kind' => 'counter',
            'unit' => 'screenshots',
            'resources' => [
                'project' => [],
            ],
        ],
    ],
];
