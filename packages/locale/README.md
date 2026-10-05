# Utopia Locale

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/locale`](https://github.com/appwrite/appwrite/tree/main/packages/locale) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

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

### Plurals

Plural translations are [ICU MessageFormat](https://unicode-org.github.io/icu/userguide/format_parse/messages/) patterns with a `count` argument, formatted with the [CLDR plural rules](https://www.unicode.org/cldr/charts/latest/supplemental/language_plural_rules.html) of the language that has the translation. Inside a pattern, `{` and `}` are ICU syntax, so keep `{{placeholders}}` in the sentence around it and pass any other value as an ICU argument instead.

```php
<?php

Locale::setLanguageFromArray('en-US', [
    'minutes' => '{count, plural, one {in # minute} other {in # minutes}}',
    'expire' => 'This code will expire {{expire}}.',
    'invites' => '{count, plural, one {# invite left for {team}} other {# invites left for {team}}}',
]);
Locale::setLanguageFromArray('ru-RU', [
    'minutes' => '{count, plural, one {через # минуту} few {через # минуты} many {через # минут} other {через # минуты}}',
]);

$locale = new Locale('ru-RU');
$locale->setFallback('en-US');

echo $locale->getPlural('minutes', 5); // prints "через 5 минут"

// Plural placeholders use the language of the translation they fill, here the en-US fallback
echo $locale->getText('expire', plurals: ['expire' => ['minutes', 21]]); // prints "This code will expire in 21 minutes."

// Patterns can take other ICU arguments
echo $locale->getPlural('invites', 2, arguments: ['team' => 'Appwrite']); // prints "2 invites left for Appwrite"
```

A language that has no translation for the plural key, or whose pattern does not compile, falls back to the fallback language and its rules. `selectordinal` and the other ICU types work the same way.

When a language loads another language's translations, pass the locale whose plural rules apply:

```php
<?php

Locale::setLanguageFromJSON('sr-RS', 'path/to/en.json', 'en');
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

Utopia Framework requires PHP 8.3 or later and the `intl` extension. We recommend using the latest PHP version whenever possible.

## Tests

```sh
composer test
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
