# Node Assertion Error Formatter

Format errors to display a diff between the actual and expected

Originally extracted from [mocha](https://github.com/mochajs/mocha)

## Usage
```js
import {format} from 'assertion-error-formatter'

format(error)
```

## API Reference

#### `format(error [, options])`

* `error`: a javascript error
* `options`: An object with the following keys:
  * `colorFns`: An object with the keys 'diffAdded', 'diffRemoved', 'errorMessage', 'errorStack'. The values are functions to colorize a string, each defaults to identity.
  * `inlineDiff`: boolean (default: false)
    * toggle between inline and unified diffs
