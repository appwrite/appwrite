<?php
require_once(__DIR__ . '/vendor/autoload.php');

$client = (new \Appwrite\Client())
  ->setEndpoint(getenv('APPWRITE_ENDPOINT'))
  ->setProject(getenv('APPWRITE_PROJECT_ID'))
  ->setKey(getenv('APPWRITE_API_KEY'));

$account = new \Appwrite\Services\Account($client);
$user = $account->get();
echo "Hello, " . $user['name'];
