<?php

/**
 * What an alert rule can watch.
 *
 * `resources` are the resource types a rule points at, each with the project
 * collection its ID is looked up in; `project` needs none, the rule watches the
 * project itself. `metrics` lists the resource types each metric is recorded
 * for, whether it is a counter summed over the rule's window or a gauge read at
 * its latest sample, and the unit its threshold is written in.
 *
 * `Appwrite\Alert\Meter` reads each metric listed here.
 */
return [
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
            'resources' => ['project'],
            'kind' => 'counter',
            'unit' => 'bytes',
        ],
        'bandwidth.outbound' => [
            'resources' => ['project'],
            'kind' => 'counter',
            'unit' => 'bytes',
        ],
        'databases.reads' => [
            'resources' => ['project', 'database'],
            'kind' => 'counter',
            'unit' => 'operations',
        ],
        'databases.writes' => [
            'resources' => ['project', 'database'],
            'kind' => 'counter',
            'unit' => 'operations',
        ],
        'databases.storage' => [
            'resources' => ['project', 'database'],
            'kind' => 'gauge',
            'unit' => 'bytes',
        ],
        'files.storage' => [
            'resources' => ['project', 'bucket'],
            'kind' => 'gauge',
            'unit' => 'bytes',
        ],
    ],
];
