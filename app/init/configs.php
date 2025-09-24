<?php

use Utopia\Config\Config;

require_once __DIR__ . '/../config/storage/resource_limits.php';

Config::load('template-runtimes', __DIR__ . '/../config/template-runtimes.php');
Config::load('events', __DIR__ . '/../config/events.php');
Config::load('auth', __DIR__ . '/../config/auth.php');
Config::load('apis', __DIR__ . '/../config/apis.php');  // List of APIs
Config::load('errors', __DIR__ . '/../config/errors.php');
Config::load('oAuthProviders', __DIR__ . '/../config/oAuthProviders.php');
Config::load('platforms', __DIR__ . '/../config/platforms.php');
Config::load('console', __DIR__ . '/../config/console.php');
Config::load('collections', __DIR__ . '/../config/collections.php');
Config::load('frameworks', __DIR__ . '/../config/frameworks.php');
Config::load('runtimes', __DIR__ . '/../config/runtimes.php');
Config::load('runtimes-v2', __DIR__ . '/../config/runtimes-v2.php');
Config::load('usage', __DIR__ . '/../config/usage.php');
Config::load('roles', __DIR__ . '/../config/roles.php');  // User roles and scopes
Config::load('scopes', __DIR__ . '/../config/scopes.php');  // User roles and scopes
Config::load('services', __DIR__ . '/../config/services.php');  // List of services
Config::load('variables', __DIR__ . '/../config/variables.php');  // List of env variables
Config::load('regions', __DIR__ . '/../config/regions.php'); // List of available regions
Config::load('avatar-browsers', __DIR__ . '/../config/avatars/browsers.php');
Config::load('avatar-credit-cards', __DIR__ . '/../config/avatars/credit-cards.php');
Config::load('avatar-flags', __DIR__ . '/../config/avatars/flags.php');
Config::load('locale-codes', __DIR__ . '/../config/locale/codes.php');
Config::load('locale-currencies', __DIR__ . '/../config/locale/currencies.php');
Config::load('locale-eu', __DIR__ . '/../config/locale/eu.php');
Config::load('locale-languages', __DIR__ . '/../config/locale/languages.php');
Config::load('locale-phones', __DIR__ . '/../config/locale/phones.php');
Config::load('locale-countries', __DIR__ . '/../config/locale/countries.php');
Config::load('locale-continents', __DIR__ . '/../config/locale/continents.php');
Config::load('locale-templates', __DIR__ . '/../config/locale/templates.php');
Config::load('storage-logos', __DIR__ . '/../config/storage/logos.php');
Config::load('storage-mimes', __DIR__ . '/../config/storage/mimes.php');
Config::load('storage-inputs', __DIR__ . '/../config/storage/inputs.php');
Config::load('storage-outputs', __DIR__ . '/../config/storage/outputs.php');
Config::load('specifications', __DIR__ . '/../config/specifications.php');
Config::load('templates-function', __DIR__ . '/../config/templates/function.php');
Config::load('templates-site', __DIR__ . '/../config/templates/site.php');
