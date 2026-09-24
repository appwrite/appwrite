# Utopia Balancer

[![Build Status](https://github.com/utopia-php/balancer/actions/workflows/tester.yml/badge.svg)](https://github.com/utopia-php/balancer/actions/workflows/tester.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/database.svg)](https://packagist.org/packages/utopia-php/balancer)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Balancer library is simple and lite library for balancing choices between multiple options. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:
```bash
composer require utopia-php/balancer
```

Balancer supports multiple algorithms. Each picks option differently, and may have different set of methods available for configuration.

### Balancer Algorithms

1. Random

`Random` algorithm pick option randomly. The same option could be picked multiple times in a row. Example:

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Balancer\Algorithm\Random;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;

$balancer = new Balancer(new Random());

$balancer->addFilter(fn (Option $option) => $option->getState('online', false) === true);

$balancer
    ->addOption(new Option([ 'hostname' => 'proxy-1', 'online' => true ]))
    ->addOption(new Option([ 'hostname' => 'proxy-2', 'online' => false ]))
    ->addOption(new Option([ 'hostname' => 'proxy-3', 'online' => true ]));

var_dump($balancer->run());
var_dump($balancer->run());
var_dump($balancer->run());
```

2. First and Last

`First` algorithm always picks first option. Similiarly, `Last` algorithm always picks last option. Example:

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Algorithm\Last;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;

$balancer = new Balancer(new First());

$balancer
    ->addOption(new Option([ 'runtime' => 'PHP' ]))
    ->addOption(new Option([ 'runtime' => 'JavaScript' ]))
    ->addOption(new Option([ 'runtime' => 'Java' ]));

var_dump($balancer->run());

$balancer = new Balancer(new Last());

$balancer
    ->addOption(new Option([ 'runtime' => 'PHP' ]))
    ->addOption(new Option([ 'runtime' => 'JavaScript' ]))
    ->addOption(new Option([ 'runtime' => 'Java' ]));

var_dump($balancer->run());
```

3. Round Robin

`RoundRobin` algorithm cycles over all options starting first. Once algorithm cycles over all options, it resets back to the beginning. Example:

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;

$balancer = new Balancer(new RoundRobin(-1));

$balancer->addFilter(fn (Option $option) => $option->getState('online', false) === true);

$balancer
    ->addOption(new Option([ 'dataCenter' => 'fra-1' ]))
    ->addOption(new Option([ 'dataCenter' => 'fra-2' ]))
    ->addOption(new Option([ 'dataCenter' => 'lon-1' ]));

var_dump($balancer->run()); // fra-1
var_dump($balancer->run()); // fra-2
var_dump($balancer->run()); // lon-1
var_dump($balancer->run()); // fra-1
var_dump($balancer->run()); // fra-2
```

When using `RoundRobin` in concurrency model, make sure to store index in atomic way. Example:

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Option;

$atomic = new Atomic(-1); // Some atomic implementation, for example: https://openswoole.com/docs/modules/swoole-atomic

function onRequest() {
    $lastIndex = $atomic->get();
    $algo = new RoundRobin($lastIndex);
    $balancer = new Balancer();

    $balancer->addFilter(fn (Option $option) => $option->getState('online', false) === true);

    $balancer
        ->addOption(new Option([ 'dataCenter' => 'fra-1' ]))
        ->addOption(new Option([ 'dataCenter' => 'fra-2' ]))
        ->addOption(new Option([ 'dataCenter' => 'lon-1' ]));

    var_dump($balancer->run());

    $atomic->cmpset($lastIndex, $algo->getIndex());
}
```

### Balancer Group

If balancer filters cause balancer to have no options to pick from, `null` will be returned. More often then not, you will need a backup logic for this scenario. You can use `Group` to create a group of multiple balancers and if one fails, next can be used as fallback. Notice `Group` tries balances in order you added them.

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Balancer\Algorithm\First;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Group;
use Utopia\Balancer\Option;

// Prepare options where each has high CPU load
$options = [
    new Option([ 'dataCenter' => 'fra-1', 'cpu' => 91 ]),
    new Option([ 'dataCenter' => 'fra-2', 'cpu' => 95 ]),
    new Option([ 'dataCenter' => 'lon-1', 'cpu' => 87 ]),
];

// Prepare balancer that allows only low CPU load options
$balancer1 = new Balancer(new First());
$balancer1->addFilter(fn ($option) => $option->getState('cpu') < 80);

// Prepare balancer that allows all options
$balancer2 = new Balancer(new First());

// Add options to both balancers
foreach ($options as $option) {
    $balancer1->addOption($option);
    $balancer2->addOption($option);
}

// Prepare group with both balancers
$group = new Group();
$group
    ->add($balancer1)
    ->add($balancer2);

// Run group to get option
$option = $group->run() ?? new Option([]);

\var_dump($option);
// We got fra-1 option. First balancer filtered out all options, but second balancer allowed any, and First algorithm picked first option
```

## System Requirements

Utopia Framework requires PHP 8.0 or later. We recommend using the latest PHP version whenever possible.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)