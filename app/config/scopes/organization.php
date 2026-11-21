<?php

// List of scopes for organization (teams) API keys

return [
    "projects.read" => [
        "description" => 'Access to read organization projects',
        "category" => "Projects",
    ],
    "projects.write" => [
        "description" =>
            "Access to create, update, and delete organization projects",
        "category" => "Projects",
    ],
    "organization.projects.keys.read" => [
        "description" => 'Access to read organization projects\' API keys',
        "category" => "Projects",
    ],
    "organization.projects.keys.write" => [
        "description" =>
            "Access to create, update, and delete organization projects' API keys",
        "category" => "Projects",
    ],
    "monitors.read" => [
        "description" => "Access to read the organization's escalation policies",
        "category" => "Alerts",
    ],
    "monitors.write" => [
        "description" =>
            "Access to create, update, and delete the organization's escalation policies",
        "category" => "Alerts",
    ],
];
