# Utopia Image

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/image`](https://github.com/appwrite/appwrite/tree/main/packages/image) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/image.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Image is a lightweight PHP library for common image manipulations. It is maintained by the [Appwrite team](https://appwrite.io).

`Utopia\Image\Image` is implemented in Rust and registered by the `utopia` PHP extension, built from [`crates/extension`](https://github.com/appwrite/appwrite/tree/main/crates/extension). This package holds what stays in PHP: `Format`, the exceptions, a stub of the class for static analysis (`stubs/Image.php`) and the tests. The last release with a PHP implementation, on ImageMagick, is 0.8.9.

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

Utopia Image requires PHP 8.1 or later and the `utopia` extension. The tests also need Imagick, which they use to build and inspect images.

## Testing

```sh
composer test
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
