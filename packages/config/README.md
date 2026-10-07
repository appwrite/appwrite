# Utopia Config

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/config`](https://github.com/utopia-php/monorepo/tree/main/packages/config) — please open issues and pull requests there.

[![Build Status](https://travis-ci.org/utopia-php/config.svg?branch=master)](https://travis-ci.com/utopia-php/config)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/config.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Config library is simple and lite library for managing application configuration. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting started

Install using Composer:
```bash
composer require utopia-php/config
```

```php
<?php

require_once './vendor/autoload.php';

use Utopia\Config\Parser\JSON;
use Utopia\Config\Attribute\Key;
use Utopia\Config\Config;
use Utopia\Config\Exception\Load;
use Utopia\Config\Exception\Parse;
use Utopia\Config\Source\File;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Integer;
use Utopia\Validator\JSON as JSONValidator;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class DatabaseConfig
{
    #[Key('db.host', new Text(length: 1024), required: true)]
    public string $host;

    #[Key('db.port', new Integer(loose: true), required: false)]
    public ?int $port;

    #[Key('db.username', new Text(length: 1024), required: true)]
    public string $username;

    #[Key('db.password', new Text(length: 1024), required: true)]
    public string $password;
    
    #[Key('db.name', new Nullable(new Text(length: 1024)), required: true)]
    public ?string $name;

    /**
     * @var array<string, mixed> $config
     */
    #[Key('db.config', new Nullable(new JSONValidator), required: true)]
    public ?array $config;

    /**
     * @var array<string> $whitelistIps
     */
    #[Key('db.whitelistIps', new ArrayList(new Text(length: 100), length: 100), required: true)]
    public array $whitelistIps;
}

$source = new File(__DIR__.'/config.json');
$parser = new JSON();

try {
    $config = Config::load($source, $parser, DatabaseConfig::class);
} catch (Load $err) {
    exit('Config could not be loaded from a file: ' . $err->getMessage());
} catch (Parse $err) {
    exit('Config could not be parsed as JSON: ' . $err->getMessage());
}

\var_dump($config);
// $config->host
// $config->port
// $config->username
// ...
```

For above example to work, make sure to setup `config.json` file too:

```json
{
  "db": {
    "host": "127.0.0.1",
    "port": 3306,
    "username": "root",
    "password": "password",
    "name": "utopia",
    "config": {
      "timeout": 3000,
      "handshakeTimeout": 5000
    },
    "whitelistIps": [
        "127.0.0.1",
        "172.17.0.0/16"
    ]
  },
}
```

> Notice dot notation is supported, and automatically finds nested objects.

Alternatively, you can load configs directly from a variable:

```php

<?php

require_once './vendor/autoload.php';

use Utopia\Config\Attribute\Key;
use Utopia\Config\Config;
use Utopia\Config\Source\Variable;
use Utopia\Config\Parser\None;
use Utopia\Validator\Whitelist;

class FirewallConfig
{
    #[Key('security-level', new Whitelist('high', 'low'), required: true)]
    public string $securityLevel;
}

$config = Config::load(
    source: new Variable([
        'security-level' => 'high',
    ]),
    parser: new None(),
    FirewallConfig::class
);
\var_dump($config);
// $config->securityLevel
```

Below is example how to combine multiple configs into one:
```php
<?php
class FirewallConfig
{
    /**
     * @var array<string> $allowIps
     */
    #[Key('ALLOW_IPS', new ArrayList(new Text(length: 100), length: 100), required: true)]
    public array $allowIps;
    
    #[Key('CAPTCHA', new Whitelist(['enabled', 'disabled']), required: true)]
    public string $captcha;
}

class CredentialsConfig
{
    #[Key('DATABASE_PASSWORD', new Text(length: 1024), required: true)]
    public string $dbPass;
    
    #[Key('CACHE_PASSWORD', new Text(length: 1024), required: true)]
    public string $cachePass;
}

class EnvironmentConfig
{
    #[Key('RATE_LIMIT_HITS', new Integer(loose: true), required: true)]
    public int $abuseHits;
    
    #[Key('RATE_LIMIT_SECONDS', new Integer(loose: true), required: true)]
    public int $abuseTime;   
}

class AppConfig
{
    #[ConfigKey]
    public FirewallConfig $firewall;
    
    #[ConfigKey]
    public CredentialsConfig $credentials;
    
    #[ConfigKey]
    public EnvironmentConfig $environment;
}

$config = Config::load(
  new Variable([
    'firewall' => Config::load(new File('firewall.json'), new JSON(), FirewallConfig::class),
    'credentials' => Config::load(new File('credentials.yml'), new YAML(), CredentialsConfig::class),
    'environment' => Config::load(new File('.env'), new Dotenv(), EnvironmentConfig::class),
  ]),
  new None(),
  AppConfig::class
);

\var_dump($config);
// $config->firewall->allowIps
// $config->credentials->dbPass
// $config->environment->abuseHits
```

You can also load environment variables instead of reading dotenv file:

```php
<?php

use Utopia\Config\Config;
use Utopia\Config\Source\Environment;
use Utopia\Config\Parser\None;

class CredentialsConfig
{
    #[Key('DATABASE_PASSWORD', new Text(length: 1024), required: true)]
    public string $dbPass;
    
    #[Key('CACHE_PASSWORD', new Text(length: 1024), required: true)]
    public string $cachePass;
}

$config = Config::load(new Environment(), new None(), CredentialsConfig::class);

\var_dump($config);
// $config->dbPass
// $config->$cachePass
```

## System requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

When using YAML adapter, or running tests with it, you need to install the YAML extension for PHP.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
