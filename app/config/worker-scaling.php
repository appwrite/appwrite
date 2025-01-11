<?php

return [
    'worker' => [
        'scaling' => [
            'enabled' => App::getEnv('_APP_WORKER_SCALING_ENABLED', 'false'),
            'min_instances' => (int) App::getEnv('_APP_WORKER_MIN_INSTANCES', '1'),
            'max_instances' => (int) App::getEnv('_APP_WORKER_MAX_INSTANCES', '10'),
            'cooldown_period' => (int) App::getEnv('_APP_WORKER_COOLDOWN_PERIOD', '300'),
            'queue_threshold' => (int) App::getEnv('_APP_WORKER_QUEUE_THRESHOLD', '1000'),
            'cpu_threshold' => (float) App::getEnv('_APP_WORKER_CPU_THRESHOLD', '80.0'),
            'memory_threshold' => (float) App::getEnv('_APP_WORKER_MEMORY_THRESHOLD', '85.0'), // Yeni eklenen bellek eşiği
        ],
    ],
];