<?php

use Utopia\Config\Adapters\PHP;
use Utopia\Config\Config;

require_once __DIR__ . '/../config/storage/resource_limits.php';

$configAdapter = new PHP();

Config::load('runtimes', __DIR__ . '/../config/runtimes.php', $configAdapter);
Config::load('runtimes-v2', __DIR__ . '/../config/runtimes-v2.php', $configAdapter);
Config::load('template-runtimes', __DIR__ . '/../config/template-runtimes.php', $configAdapter);
Config::load('events', __DIR__ . '/../config/events.php', $configAdapter);
Config::load('auth', __DIR__ . '/../config/auth.php', $configAdapter);
Config::load('apis', __DIR__ . '/../config/apis.php', $configAdapter);  // List of APIs
Config::load('errors', __DIR__ . '/../config/errors.php', $configAdapter);
Config::load('oAuthProviders', __DIR__ . '/../config/oAuthProviders.php', $configAdapter);
Config::load('sdks', __DIR__ . '/../config/sdks.php', $configAdapter);
Config::load('platform', __DIR__ . '/../config/platform.php', $configAdapter);
Config::load('console', __DIR__ . '/../config/console.php', $configAdapter);
Config::load('collections', __DIR__ . '/../config/collections.php', $configAdapter);
Config::load('frameworks', __DIR__ . '/../config/frameworks.php', $configAdapter);
Config::load('usage', __DIR__ . '/../config/usage.php', $configAdapter);
Config::load('roles', __DIR__ . '/../config/roles.php', $configAdapter);  // User roles and scopes
Config::load('scopes', __DIR__ . '/../config/scopes.php', $configAdapter);  // User roles and scopes
Config::load('services', __DIR__ . '/../config/services.php', $configAdapter);  // List of services
Config::load('variables', __DIR__ . '/../config/variables.php', $configAdapter);  // List of env variables
Config::load('regions', __DIR__ . '/../config/regions.php', $configAdapter); // List of available regions
Config::load('avatar-browsers', __DIR__ . '/../config/avatars/browsers.php', $configAdapter);
Config::load('avatar-credit-cards', __DIR__ . '/../config/avatars/credit-cards.php', $configAdapter);
Config::load('avatar-flags', __DIR__ . '/../config/avatars/flags.php', $configAdapter);
Config::load('locale-codes', __DIR__ . '/../config/locale/codes.php', $configAdapter);
Config::load('locale-currencies', __DIR__ . '/../config/locale/currencies.php', $configAdapter);
Config::load('locale-eu', __DIR__ . '/../config/locale/eu.php', $configAdapter);
Config::load('locale-languages', __DIR__ . '/../config/locale/languages.php', $configAdapter);
Config::load('locale-phones', __DIR__ . '/../config/locale/phones.php', $configAdapter);
Config::load('locale-countries', __DIR__ . '/../config/locale/countries.php', $configAdapter);
Config::load('locale-continents', __DIR__ . '/../config/locale/continents.php', $configAdapter);
Config::load('locale-templates', __DIR__ . '/../config/locale/templates.php', $configAdapter);
Config::load('storage-logos', __DIR__ . '/../config/storage/logos.php', $configAdapter);
Config::load('storage-mimes', __DIR__ . '/../config/storage/mimes.php', $configAdapter);
Config::load('storage-inputs', __DIR__ . '/../config/storage/inputs.php', $configAdapter);
Config::load('storage-outputs', __DIR__ . '/../config/storage/outputs.php', $configAdapter);
Config::load('specifications', __DIR__ . '/../config/specifications.php', $configAdapter);
Config::load('templates-function', __DIR__ . '/../config/templates/function.php', $configAdapter);
Config::load('templates-site', __DIR__ . '/../config/templates/site.php', $configAdapter);
