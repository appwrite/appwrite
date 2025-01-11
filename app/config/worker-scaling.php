<?php

return [
    'scaling' => [
        'workers' => [
            'deletes' => [
                'min_instances' => 1,
                'max_instances' => 5,
                'scale_up_threshold' => [
                    'queue_length' => 1000,
                    'cpu_usage' => 80,
                    'memory_usage' => 85
                ],
                'scale_down_threshold' => [
                    'queue_length' => 100,
                    'cpu_usage' => 20,
                    'memory_usage' => 40
                ],
                'cooldown_period' => 300 // 5 minutes between scaling actions
            ],
            'certificates' => [
                'min_instances' => 1,
                'max_instances' => 3,
                'scale_up_threshold' => [
                    'queue_length' => 500,
                    'cpu_usage' => 80,
                    'memory_usage' => 85
                ],
                'scale_down_threshold' => [
                    'queue_length' => 50,
                    'cpu_usage' => 20,
                    'memory_usage' => 40
                ],
                'cooldown_period' => 300
            ]
        ]
    ]
];
