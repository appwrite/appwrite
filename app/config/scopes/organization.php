<?php

// List of scopes for organization (teams) API keys

return [
    "platforms.read" => [
        "description" => 'Access to read project\'s platforms',
    ],
    "platforms.write" => [
        "description" =>
            'Access to create, update, and delete project\'s platforms',
    ],
    "projects.read" => [
        "description" => 'Access to read organization\'s projects',
    ],
    "projects.write" => [
        "description" =>
            "Access to create, update, and delete projects in organization",
    ],
    "keys.read" => [
        "description" => 'Access to read project\'s API keys',
    ],
    "keys.write" => [
        "description" =>
            "Access to create, update, and delete project\'s API keys",
    ],
    "devKeys.read" => [
        "description" => 'Access to read project\'s development keys',
    ],
    "devKeys.write" => [
        "description" =>
            "Access to create, update, and delete project\'s development keys",
    ],
    "webhooks.read" => [
        "description" =>
            "Access to read project\'s webhooks",
    ],
    "webhooks.write" => [
        "description" =>
            "Access to create, update, and delete project\'s webhooks",
    ],
];
