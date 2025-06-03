# regexp-match-indices

This package provides a polyfill/shim for the [RegExp Match Indices](https://github.com/tc39/proposal-regexp-match-indices) proposal.

The implementation is a replacement for `RegExp.prototype.exec` that approximates the beahvior of the proposal. Because `RegExp.prototype.exec` depends on a receiver (the `this` value), the main export accepts the `RegExp` to operate on as the first argument.

## Installation

```sh
npm install regexp-match-indices
```

## Usage

### Standalone

```js
const execWithIndices = require("regexp-match-indices");

const text = "zabbcdef";
const re = new RegExp("ab*(cd(?<Z>ef)?)");
const result = execWithIndices(re, text);
console.log(result.indices);    // [[1, 8], [4, 8], [6, 8]]
```

### Shim

```js
require("regexp-match-indices").shim();
// or
require("regexp-match-indices/shim")();
// or
require("regexp-match-indices/auto");

const text = "zabbcdef";
const re = new RegExp("ab*(cd(?<Z>ef)?)");
const result = re.exec(re, text);
console.log(result.indices);    // [[1, 8], [4, 8], [6, 8]]
```

## Configuration

The polyfill can be run in two modes: `"lazy"` (default) or `"spec-compliant"`. In `"spec-compliant"` mode, the `indices` property is populated and stored on the result as soon as `exec()` is called. This can have a significant performance penalty for existing RegExp's that do not use this feature. By default, the polyfill operates in `"lazy"` mode, where the `indices` property is defined using a getter and is only computed when first requested.

You can specify the configuration globally using the following:

```js
require("regexp-match-indices").config.mode = "spec-compliant"; // or "lazy"
// or
require("regexp-match-indices/config").mode = "spec-compliant"; // or "lazy"
```