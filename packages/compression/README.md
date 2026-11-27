# Utopia Compression

> [!IMPORTANT]
> This repository is a read-only mirror of [`packages/compression`](https://github.com/appwrite/appwrite/tree/main/packages/compression) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/compression.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Compression is simple and lite library for managing file compression. It supports multiple compression algorithms. This library is aiming to be as simple and easy to learn and use. This library is maintained by the [Appwrite team](https://appwrite.io).

This library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project.


## Getting started

Install using Composer:
```bash
composer require utopia-php/compression
```

## System requirements

Utopia Compression requires PHP 8.4 or later and the `utopia` extension (`ext-utopia`, built from
[`crates/extension`](https://github.com/appwrite/appwrite/tree/main/crates/extension) in
appwrite/appwrite). Every class of `Utopia\Compression` comes from the extension, which links the
codecs itself, so no PECL extension is needed except `xz` for the XZ algorithm. This package holds
their signatures for static analysis and editors (`stubs/`) and the tests. 0.1.x is the last
release with the classes in PHP.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
