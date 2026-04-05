<?php

/**
 * DI Container Resources Migration
 * 
 * This file provides DI Container setup for migrating from static Http::setResource()
 * to runtime-owned Utopia\DI\Container instances.
 */

use Utopia\DI\Container;
use Utopia\DI\Dependency;

/**
 * Create and configure DI Container with all Appwrite dependencies
 */
function createDIContainer(): Container
{
    $container = new Container();

    // Core services
    $container->set('log', fn () => new \Utopia\Logger\Log());
    $container->set('logger', fn (Container $c) => $c->get('register')->get('logger'), ['register']);
    $container->set('hooks', fn (Container $c) => $c->get('register')->get('hooks'), ['register']);
    $container->set('register', fn () => $GLOBALS['register'] ?? null);
    
    // Locale
    $container->set('locale', function () {
        $locale = new \Utopia\Locale\Locale(\Utopia\System\System::getEnv('_APP_LOCALE', 'en'));
        $locale->setFallback(\Utopia\System\System::getEnv('_APP_LOCALE', 'en'));
        return $locale;
    });
    
    $container->set('localeCodes', fn () => array_map(
        fn ($locale) => $locale['code'], 
        \Utopia\Config\Config::getParam('locale-codes', [])
    ));

    // Platform config
    $container->set('platform', fn () => \Utopia\Config\Config::getParam('platform', []));

    // Geolocation - Uses Docker geo adapter with MaxMind fallback (PR #10824)
    $container->set('geodb', function () {
        $maxMindReader = new \MaxMind\Db\Reader(__DIR__ . '/../assets/dbip/dbip-country-lite-2025-12.mmdb');
        return new \Appwrite\Network\Geo\DockerGeoAdapter($maxMindReader);
    });

    // Auth proofs
    $container->set('proofForPassword', function () {
        $hash = new \Utopia\Auth\Hashes\Argon2();
        $hash->setMemoryCost(7168)->setTimeCost(4)->setThreads(1);
        $password = new \Utopia\Auth\Proofs\Password();
        $password->setHash($hash);
        return $password;
    });

    $container->set('proofForToken', function () {
        $token = new \Utopia\Auth\Proofs\Token();
        $token->setHash(new \Utopia\Auth\Hashes\Sha());
        return $token;
    });

    $container->set('proofForCode', function () {
        $code = new \Utopia\Auth\Proofs\Code();
        $code->setHash(new \Utopia\Auth\Hashes\Sha());
        return $code;
    });

    // Store
    $container->set('store', fn () => new \Utopia\Auth\Store());

    return $container;
}

/**
 * Get dependency from container with fallback to legacy register
 * This enables gradual migration from static to DI pattern
 */
function getResource(string $name, Container $container)
{
    if ($container->has($name)) {
        return $container->get($name);
    }

    // Fallback to legacy register during migration
    $register = $GLOBALS['register'] ?? null;
    if ($register && $register->has($name)) {
        return $register->get($name);
    }

    throw new \Exception("Resource not found: {$name}");
}
