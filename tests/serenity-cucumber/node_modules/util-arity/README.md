# Arity

[![NPM version][npm-image]][npm-url]
[![NPM downloads][downloads-image]][downloads-url]
[![Build status][travis-image]][travis-url]
[![Test coverage][coveralls-image]][coveralls-url]

> Set a functions arity (the argument count) by proxying function calls.

**P.S.** If you need need to enforce arity and don't care about argument length or `this`, use [`nary`](https://github.com/blakeembrey/nary). It's magnitudes faster than using `.apply` to proxy arguments.

## When would I use this?

It's unlikely you'll need to use this utility in everyday development. The reason I wrote it was for functional utilities and backward compatibility with user expectations. For example, many modules use function arity to decide how the function behaves (e.g. error middleware in `express`, callbacks in `mocha`).

## Installation

```
npm install util-arity --save
```

## Usage

```javascript
var fn = function () {};
var arity = require('util-arity');

var oneArg = arity(1, fn);
var twoArgs = arity(2, fn);
var threeArgs = arity(3, fn);

oneArgs.length; //=> 1
twoArgs.length; //=> 2
threeArgs.length; //=> 3
```

## TypeScript

The typings for this project are available for node module resolution with TypeScript.

## License

MIT

[npm-image]: https://img.shields.io/npm/v/util-arity.svg?style=flat
[npm-url]: https://npmjs.org/package/util-arity
[downloads-image]: https://img.shields.io/npm/dm/util-arity.svg?style=flat
[downloads-url]: https://npmjs.org/package/util-arity
[travis-image]: https://img.shields.io/travis/blakeembrey/arity.svg?style=flat
[travis-url]: https://travis-ci.org/blakeembrey/arity
[coveralls-image]: https://img.shields.io/coveralls/blakeembrey/arity.svg?style=flat
[coveralls-url]: https://coveralls.io/r/blakeembrey/arity?branch=master
