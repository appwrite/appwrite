# Utopia Image

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/image`](https://github.com/appwrite/appwrite/tree/main/packages/image) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/image.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Image is a lightweight PHP library for common image manipulations. It is maintained by the [Appwrite team](https://appwrite.io).

`Utopia\Image\Canvas` and `Utopia\Image\Font` are implemented in Rust and registered by the `utopia` PHP extension, built from [`crates/extension`](https://github.com/appwrite/appwrite/tree/main/crates/extension). This package holds what is plain PHP: the value objects a canvas takes, the `Format` and `Gravity` enums, the exceptions, a stub of the two classes for static analysis (`stubs/Canvas.php`) and the tests. The last release with a PHP implementation, on ImageMagick, is 0.8.9.

## Getting started

```bash
composer require utopia-php/image
```

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Image\Border;
use Utopia\Image\Canvas;
use Utopia\Image\Format;
use Utopia\Image\Gravity;
use Utopia\Image\Limits;
use Utopia\Image\Transform;

// Reads the header only: size, format and orientation are known without decoding.
$canvas = new Canvas(\file_get_contents('photo.jpg'), new Limits(width: 8000, height: 8000));
echo "{$canvas->width}x{$canvas->height} {$canvas->format->value}\n";

// One preview, described up front. Nothing is decoded until encode().
$thumbnail = $canvas
    ->apply(new Transform(
        width: 300,
        height: 300,
        gravity: Gravity::Top,
        border: new Border(width: 2, color: '#ff0000'),
        radius: 16,
    ))
    ->encode(Format::Webp, quality: 80);

\file_put_contents('thumbnail.webp', $thumbnail);
```

A canvas is immutable: `apply()` and `draw()` return a new one, and the original can be reused for another size. A `Transform` runs its parts in a fixed order (crop, opacity, background, border, rounded corners, rotation); for another order, chain several `apply()` calls.

Drawing on a blank canvas:

```php
use Utopia\Image\Blank;
use Utopia\Image\Font;
use Utopia\Image\Text;

$font = new Font('Inter-Regular.ttf');
$avatar = (new Canvas(new Blank(256, 256, '#4f4f4f')))
    ->draw(new Text('AB', $font, size: 102, color: '#ffffff', x: 130, y: 162))
    ->encode(Format::Png);
```

### Safe by default

- **Limits.** Every canvas is bounded by a `Limits`: 16,000 pixels a side and 512 MiB of decoded pixels unless you pass your own. A file past them is refused from its header, before any pixel is decoded. There is no global setting and no way to switch limits off.
- **Formats.** `Limits::$formats` lists the formats a canvas may be read from, so an endpoint that expects JPEG and PNG never hands other data to a decoder.
- **Validation.** `Transform`, `Border`, `Point`, `Blank` and `Limits` reject impossible values when they are constructed.
- **Bytes out only.** `encode()` returns a string and writes no metadata; nothing writes to a path.

### Errors

`Utopia\Image\Exception\ImageException`, with `UnsupportedFormatException`, `CorruptImageException` and `LimitExceededException` for what an image or the system makes fail, and `\InvalidArgumentException` for an argument that could never work. A file with a readable header and damaged pixel data fails in `encode()`.

## System requirements

PHP 8.2 or later and the `utopia` extension. The tests also need GD, which they use to build and inspect images.

## Testing

```sh
composer test
```

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
