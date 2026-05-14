<?php

// List of scopes for organization (teams) API keys

return [
    "projects.read" => [
        "description" => 'Access to read organization\'s projects',
    ],
    "projects.write" => [
        "description" =>
            "Access to create, update, and delete projects in organization",
    ],
    "devKeys.read" => [
        "description" => 'Access to read project\'s development keys',
    ],
    "devKeys.write" => [
        "description" =>
            "Access to create, update, and delete project\'s development keys",
    ],
];
