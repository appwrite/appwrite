<?php

use Appwrite\Database\Database;

$collections = [
    'movies' => [
        '$collection' => Database::COLLECTION_COLLECTIONS,
        '$permissions' => ['read' => ['*']],
        'name' => 'Movies',
        'rules' => [
            [
                '$collection' => Database::COLLECTION_RULES,
                '$permissions' => ['read' => ['*']],
                'label' => 'Name',
                'key' => 'name',
                'type' => Database::VAR_TEXT,
                'default' => '',
                'required' => true,
                'array' => false,
            ],
            [
                '$collection' => Database::COLLECTION_RULES,
                '$permissions' => ['read' => ['*']],
                'label' => 'Release Year',
                'key' => 'releaseYear',
                'type' => Database::VAR_INTEGER,
                'default' => 0,
                'required' => true,
                'array' => false,
            ],
            [
                '$collection' => Database::COLLECTION_RULES,
                '$permissions' => ['read' => ['*']],
                'label' => 'Director',
                'key' => 'director',
                'type' => Database::VAR_TEXT,
                'default' => '',
                'required' => true,
                'array' => false,
            ],
            [
                '$collection' => Database::COLLECTION_RULES,
                '$permissions' => ['read' => ['*']],
                'label' => 'Generes',
                'key' => 'generes',
                'type' => Database::VAR_TEXT,
                'default' => '',
                'required' => true,
                'array' => true,
            ],
            [
                '$collection' => Database::COLLECTION_RULES,
                '$permissions' => ['read' => ['*']],
                'label' => 'Langauges',
                'key' => 'langauges',
                'type' => Database::VAR_TEXT,
                'default' => '',
                'required' => true,
                'array' => true,
            ],
        ]
    ]
];

$movies = [
    [
        'name' => '',
        'releaseYear' => '',
        'director' => '',
        'generes' => [],
        'langauges' => [],
    ],
    [
        'name' => 'Black Panther',
        'releaseYear' => '2018',
        'director' => 'Ryan Coogler',
        'generes' => ['Action', 'Adventure'],
        'langauges' => ['Xhosa', 'English', 'Korean', 'Swahili'],
    ],
    [
        'name' => 'Avengers: Infinity War',
        'releaseYear' => '2018',
        'director' => 'Ryan Coogler',
        'generes' => ['Action', 'Adventure'],
        'langauges' => ['Xhosa', 'English', 'Korean', 'Swahili'],
    ],
];
