<?php

use Utopia\Config\Adapters\PHP;
use Utopia\Config\Config;

require_once __DIR__ . '/../config/storage/resource_limits.php';

Config::load('template-runtimes', __DIR__ . '/../config/template-runtimes.php', new PHP());
Config::load('events', __DIR__ . '/../config/events.php', new PHP());
Config::load('auth', __DIR__ . '/../config/auth.php', new PHP());
Config::load('apis', __DIR__ . '/../config/apis.php', new PHP());  // List of APIs
Config::load('errors', __DIR__ . '/../config/errors.php', new PHP());
Config::load('oAuthProviders', __DIR__ . '/../config/oAuthProviders.php', new PHP());
Config::load('platforms', __DIR__ . '/../config/platforms.php', new PHP());
Config::load('console', __DIR__ . '/../config/console.php', new PHP());
Config::load('collections', __DIR__ . '/../config/collections.php', new PHP());
Config::load('frameworks', __DIR__ . '/../config/frameworks.php', new PHP());
Config::load('runtimes', __DIR__ . '/../config/runtimes.php', new PHP());
Config::load('runtimes-v2', __DIR__ . '/../config/runtimes-v2.php', new PHP());
Config::load('usage', __DIR__ . '/../config/usage.php', new PHP());
Config::load('roles', __DIR__ . '/../config/roles.php', new PHP());  // User roles and scopes
Config::load('scopes', __DIR__ . '/../config/scopes.php', new PHP());  // User roles and scopes
Config::load('services', __DIR__ . '/../config/services.php', new PHP());  // List of services
Config::load('variables', __DIR__ . '/../config/variables.php', new PHP());  // List of env variables
Config::load('regions', __DIR__ . '/../config/regions.php', new PHP()); // List of available regions
Config::load('avatar-browsers', __DIR__ . '/../config/avatars/browsers.php', new PHP());
Config::load('avatar-credit-cards', __DIR__ . '/../config/avatars/credit-cards.php', new PHP());
Config::load('avatar-flags', __DIR__ . '/../config/avatars/flags.php', new PHP());
Config::load('locale-codes', __DIR__ . '/../config/locale/codes.php', new PHP());
Config::load('locale-currencies', __DIR__ . '/../config/locale/currencies.php', new PHP());
Config::load('locale-eu', __DIR__ . '/../config/locale/eu.php', new PHP());
Config::load('locale-languages', __DIR__ . '/../config/locale/languages.php', new PHP());
Config::load('locale-phones', __DIR__ . '/../config/locale/phones.php', new PHP());
Config::load('locale-countries', __DIR__ . '/../config/locale/countries.php', new PHP());
Config::load('locale-continents', __DIR__ . '/../config/locale/continents.php', new PHP());
Config::load('locale-templates', __DIR__ . '/../config/locale/templates.php', new PHP());
Config::load('storage-logos', __DIR__ . '/../config/storage/logos.php', new PHP());
Config::load('storage-mimes', __DIR__ . '/../config/storage/mimes.php', new PHP());
Config::load('storage-inputs', __DIR__ . '/../config/storage/inputs.php', new PHP());
Config::load('storage-outputs', __DIR__ . '/../config/storage/outputs.php', new PHP());
Config::load('specifications', __DIR__ . '/../config/specifications.php', new PHP());
Config::load('templates-function', __DIR__ . '/../config/templates/function.php', new PHP());
Config::load('templates-site', __DIR__ . '/../config/templates/site.php', new PHP());
