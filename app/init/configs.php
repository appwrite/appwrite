<?php

use Utopia\Config\Config;

require_once __DIR__ . '/../config/storage/resource_limits.php';

Config::setParam('runtimes', include __DIR__ . '/../config/runtimes.php');
Config::setParam('runtimes-v2', include __DIR__ . '/../config/runtimes-v2.php');
Config::setParam('template-runtimes', include __DIR__ . '/../config/template-runtimes.php');
Config::setParam('events', include __DIR__ . '/../config/events.php');
Config::setParam('auth', include __DIR__ . '/../config/auth.php');
Config::setParam('protocols', include __DIR__ . '/../config/protocols.php');
Config::setParam('errors', include __DIR__ . '/../config/errors.php');
Config::setParam('oAuthProviders', include __DIR__ . '/../config/oAuthProviders.php');
Config::setParam('vcs', include __DIR__ . '/../config/vcs.php');
Config::setParam('sdks', include __DIR__ . '/../config/sdks.php');
Config::setParam('platform', include __DIR__ . '/../config/platform.php');
Config::setParam('console', include __DIR__ . '/../config/console.php');
Config::setParam('collections', include __DIR__ . '/../config/collections.php');
Config::setParam('frameworks', include __DIR__ . '/../config/frameworks.php');
Config::setParam('usage', include __DIR__ . '/../config/usage.php');
Config::setParam('roles', include __DIR__ . '/../config/roles.php');  // User roles and scopes
Config::setParam('projectScopes', include __DIR__ . '/../config/scopes/project.php');
Config::setParam('organizationScopes', include __DIR__ . '/../config/scopes/organization.php');
Config::setParam('accountScopes', include __DIR__ . '/../config/scopes/account.php');
Config::setParam('computeScopes', include __DIR__ . '/../config/scopes/compute.php');
Config::setParam('services', include __DIR__ . '/../config/services.php');  // List of services
Config::setParam('workers', include __DIR__ . '/../config/workers.php');  // Queue workers (name → queue / coroutines)
Config::setParam('onboarding', include __DIR__ . '/../config/onboarding.php');  // Project onboarding stages → routes
Config::setParam('variables', include __DIR__ . '/../config/variables.php');  // List of env variables
Config::setParam('regions', include __DIR__ . '/../config/regions.php'); // List of available regions
Config::setParam('avatar-browsers', include __DIR__ . '/../config/avatars/browsers.php');
Config::setParam('avatar-credit-cards', include __DIR__ . '/../config/avatars/credit-cards.php');
Config::setParam('avatar-flags', include __DIR__ . '/../config/avatars/flags.php');
Config::setParam('locale-codes', include __DIR__ . '/../config/locale/codes.php');
Config::setParam('locale-currencies', include __DIR__ . '/../config/locale/currencies.php');
Config::setParam('locale-eu', include __DIR__ . '/../config/locale/eu.php');
Config::setParam('locale-languages', include __DIR__ . '/../config/locale/languages.php');
Config::setParam('locale-phones', include __DIR__ . '/../config/locale/phones.php');
Config::setParam('locale-countries', include __DIR__ . '/../config/locale/countries.php');
Config::setParam('locale-continents', include __DIR__ . '/../config/locale/continents.php');
Config::setParam('locale-templates', include __DIR__ . '/../config/locale/templates.php');
Config::setParam('storage-logos', include __DIR__ . '/../config/storage/logos.php');
Config::setParam('storage-mimes', include __DIR__ . '/../config/storage/mimes.php');
Config::setParam('storage-inputs', include __DIR__ . '/../config/storage/inputs.php');
Config::setParam('storage-outputs', include __DIR__ . '/../config/storage/outputs.php');
Config::setParam('storage-formats', include __DIR__ . '/../config/storage/formats.php');
Config::setParam('specifications', include __DIR__ . '/../config/specifications.php');
Config::setParam('templates-function', include __DIR__ . '/../config/templates/function.php');
Config::setParam('templates-site', include __DIR__ . '/../config/templates/site.php');
Config::setParam('cors', include __DIR__ . '/../config/cors.php');
