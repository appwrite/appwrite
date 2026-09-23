# Utopia Image

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/image`](https://github.com/utopia-php/monorepo/tree/main/packages/image) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/image.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Image is a lightweight PHP library for common image manipulations. It is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

Install using Composer:

```bash
composer require utopia-php/image
```

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Image\Image;

//crop image
$image = new Image(\file_get_contents('image.jpg'));
$target = 'image_100x100.jpg';
$image->crop(100, 100, Image::GRAVITY_TOP_LEFT);
$image->save($target, 'jpg', 100);

$image = new Image(\file_get_contents('image.jpg'));
$target = 'image_border.jpg';
$image->setBorder(2, "#ff0000"); //add border 2 px, red
$image->setRotation(45); //rotate 45 degree
$image->save($target, 'jpg', 100);


$image = new Image(\file_get_contents('image.jpg'));
$target = 'image_border.jpg';
$image->setOpacity(0.2); //set opacity
$image->save($target, 'png', 100);

```

## System requirements

Utopia Image requires PHP 8.1 or later. We recommend using the latest PHP version whenever possible.

## Testing

```sh
composer test
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
