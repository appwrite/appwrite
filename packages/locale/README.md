# Utopia Locale

[![Build Status](https://travis-ci.org/utopia-php/locale.svg?branch=master)](https://travis-ci.com/utopia-php/locale)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/locale.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia framework locale library is simple and lite library for managing application translations and localization. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project it is dependency free and can be used as standalone with any other PHP project or framework.

## Getting Started

Install using composer:

```bash
composer require utopia-php/locale
```

Init in your application:

```php
<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Locale\Locale;

// Init translations
Locale::setLanguageFromArray('en-US', [
    'hello' => 'Hello',
    'world' => 'World',
    'likes' => 'You have {{likesAmount}} likes and {{commentsAmount}} comments.'
]); // Set English
Locale::setLanguageFromArray('he-IL', ['hello' => 'שלום',]); // Set Hebrew
Locale::setLanguageFromJSON('hi-IN', 'path/to/translations.json'); // Set Hindi

// Create locale instance
$locale = new Locale('en-US'); // en-US will be set as default language

// Get translation
echo $locale->getText('hello'); // prints "Hello"
echo $locale->getText('world'); // prints "World"

// Use placeholders
echo $locale->getText('likes', [ 'likesAmount' => 12, 'commentsAmount' => 55 ]); // prints "You have 12 likes and 55 comments."
echo $locale->getText('likes'); // prints "You have {{likesAmount}} likes and {{commentsAmount}} comments.". If you don't provide placeholder value, the string is returned unchanged.

// Get translation of different language
$locale->setDefault('he-IL');
echo $locale->getText('hello'); // prints "שלום"
```

## Expected Structure of Translations

Each translation is a **key-value** pair. The **key** is an identifier that represents a string in your app. The value is the translation in the specified locale.

When using `setLanguageFromArray($code, $translations)` for the `en-US` locale, you need to specify the translation array in the following format:

### Translations Array

```php
<?php
    $translations = [
        'app.landing.title' => 'Welcome to My App.',
        'app.landing.cta' => 'Click Here!',
    ]
```

When using `setLanguageFromJSON($code, $path)` for the `en-US` locale you need to specify a path to the translation JSON file which should be in the following format:

### JSON File

```json
{
 "app.landing.title": "Welcome to My App.",
 "app.landing.cta": "Click Here!"
}
```

## System Requirements

Utopia Framework requires PHP 7.4 or later. We recommend using the latest PHP version whenever possible.

## Tests

To run the tests, first you need to install libraries:

```shell
docker run --rm --interactive --tty \
  --volume $PWD:/app \
  composer update --ignore-platform-reqs --optimize-autoloader --no-plugins --no-scripts --prefer-dist
```

Finally, you can run the tests:

```shell
docker run --rm -v $(pwd):$(pwd):rw -w $(pwd) php:7.4-cli-alpine sh -c "vendor/bin/phpunit tests/Locale/LocaleTest.php"
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
