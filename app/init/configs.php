<?php

use Utopia\Config\Adapters\PHP;
use Utopia\Config\Config;

require_once __DIR__ . '/../config/storage/resource_limits.php';

$configAdapter = new PHP();

$_configProfile = [];
$_configMem = memory_get_usage();

$configs = [
    'runtimes' => __DIR__ . '/../config/runtimes.php',
    'runtimes-v2' => __DIR__ . '/../config/runtimes-v2.php',
    'template-runtimes' => __DIR__ . '/../config/template-runtimes.php',
    'events' => __DIR__ . '/../config/events.php',
    'auth' => __DIR__ . '/../config/auth.php',
    'apis' => __DIR__ . '/../config/apis.php',
    'errors' => __DIR__ . '/../config/errors.php',
    'oAuthProviders' => __DIR__ . '/../config/oAuthProviders.php',
    'sdks' => __DIR__ . '/../config/sdks.php',
    'platform' => __DIR__ . '/../config/platform.php',
    'console' => __DIR__ . '/../config/console.php',
    'collections' => __DIR__ . '/../config/collections.php',
    'frameworks' => __DIR__ . '/../config/frameworks.php',
    'usage' => __DIR__ . '/../config/usage.php',
    'roles' => __DIR__ . '/../config/roles.php',
    'projectScopes' => __DIR__ . '/../config/scopes/project.php',
    'organizationScopes' => __DIR__ . '/../config/scopes/organization.php',
    'accountScopes' => __DIR__ . '/../config/scopes/account.php',
    'services' => __DIR__ . '/../config/services.php',
    'variables' => __DIR__ . '/../config/variables.php',
    'regions' => __DIR__ . '/../config/regions.php',
    'avatar-browsers' => __DIR__ . '/../config/avatars/browsers.php',
    'avatar-credit-cards' => __DIR__ . '/../config/avatars/credit-cards.php',
    'avatar-flags' => __DIR__ . '/../config/avatars/flags.php',
    'locale-codes' => __DIR__ . '/../config/locale/codes.php',
    'locale-currencies' => __DIR__ . '/../config/locale/currencies.php',
    'locale-eu' => __DIR__ . '/../config/locale/eu.php',
    'locale-languages' => __DIR__ . '/../config/locale/languages.php',
    'locale-phones' => __DIR__ . '/../config/locale/phones.php',
    'locale-countries' => __DIR__ . '/../config/locale/countries.php',
    'locale-continents' => __DIR__ . '/../config/locale/continents.php',
    'locale-templates' => __DIR__ . '/../config/locale/templates.php',
    'storage-logos' => __DIR__ . '/../config/storage/logos.php',
    'storage-mimes' => __DIR__ . '/../config/storage/mimes.php',
    'storage-inputs' => __DIR__ . '/../config/storage/inputs.php',
    'storage-outputs' => __DIR__ . '/../config/storage/outputs.php',
    'specifications' => __DIR__ . '/../config/specifications.php',
    'templates-function' => __DIR__ . '/../config/templates/function.php',
    'templates-site' => __DIR__ . '/../config/templates/site.php',
    'cors' => __DIR__ . '/../config/cors.php',
];

foreach ($configs as $key => $path) {
    $before = memory_get_usage();
    Config::load($key, $path, $configAdapter);
    $cost = memory_get_usage() - $before;
    if ($cost > 1024) { // only log configs > 1KB
        $_configProfile[$key] = $cost;
    }
}

// Sort by cost descending and print
arsort($_configProfile);
foreach ($_configProfile as $key => $cost) {
    $costKB = round($cost / 1024);
    error_log("MEMPROFILE config: {$key} = +{$costKB}KB");
}
$totalCost = memory_get_usage() - $_configMem;
error_log("MEMPROFILE config: TOTAL = +" . round($totalCost / 1024) . "KB");
unset($_configProfile, $_configMem, $configs, $totalCost);
