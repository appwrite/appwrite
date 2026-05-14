<?php

// List of scopes for organization (teams) API keys

return [
    "projects.read" => [
        "description" => 'Access to read organization projects.',
        'category' => 'Projects'
    ],
    "projects.write" => [
        "description" =>
            "Access to create, update, and delete organization projects.",
        'category' => 'Projects'
    ],
    "devKeys.read" => [
        "description" => 'Access to read project\'s development keys',
        'deprecated' => true,
        'category' => 'Other'
    ],
    "devKeys.write" => [
        "description" =>
            "Access to create, update, and delete project\'s development keys",
        'deprecated' => true,
        'category' => 'Other'
    ],
];
