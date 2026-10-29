<?php

/**
 * What a monitor can check.
 *
 * `types` are the kinds of check a monitor runs, each with its own create and
 * update routes under `/v1/monitors/<type>`; `usage` reads the project's usage
 * stats. The rest describes usage monitors: `resources` are what a usage
 * monitor's `resourceType` rule can scope it to, each with the project
 * collection its ID is looked up in (`project` needs none, it is the project
 * itself). `metrics` lists the resources each metric is recorded for, whether
 * it is a counter summed over the monitor's window or a gauge read at its
 * latest sample, the unit its threshold is written in, and the name
 * notifications show for it. An edition adds types, resources and metrics here.
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
        ],
        'bucket' => [
            'collection' => 'buckets',
        ],
    ],
    'metrics' => [
        'bandwidth.inbound' => [
            'label' => 'Inbound bandwidth',
            'resources' => ['project'],
            'kind' => 'counter',
            'unit' => 'bytes',
        ],
        'bandwidth.outbound' => [
            'label' => 'Outbound bandwidth',
            'resources' => ['project'],
            'kind' => 'counter',
            'unit' => 'bytes',
        ],
        'databases.reads' => [
            'label' => 'Database reads',
            'resources' => ['project', 'database'],
            'kind' => 'counter',
            'unit' => 'operations',
        ],
        'databases.writes' => [
            'label' => 'Database writes',
            'resources' => ['project', 'database'],
            'kind' => 'counter',
            'unit' => 'operations',
        ],
        'databases.storage' => [
            'label' => 'Database storage',
            'resources' => ['project', 'database'],
            'kind' => 'gauge',
            'unit' => 'bytes',
        ],
        'files.storage' => [
            'label' => 'File storage',
            'resources' => ['project', 'bucket'],
            'kind' => 'gauge',
            'unit' => 'bytes',
        ],
    ],
];
