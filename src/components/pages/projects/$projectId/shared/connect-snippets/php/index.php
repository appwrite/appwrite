<?php
require_once(__DIR__ . '/vendor/autoload.php');

$client = (new \Appwrite\Client())
  ->setEndpoint(getenv('APPWRITE_ENDPOINT'))
  ->setProject(getenv('APPWRITE_PROJECT_ID'))
  ->setKey(getenv('APPWRITE_API_KEY'));

$project = new \Appwrite\Services\Project($client);

$policy = $project->updatePasswordStrengthPolicy(
  min: 8,
  uppercase: true,
  number: true,
  symbols: true
);

echo json_encode($policy, JSON_PRETTY_PRINT) . "\n";

$policies = $project->listPolicies();

echo json_encode($policies, JSON_PRETTY_PRINT) . "\n";
