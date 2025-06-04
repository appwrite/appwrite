# filenamify

> Convert a string to a valid safe filename

On Unix-like systems, `/` is reserved. On Windows, [`<>:"/\|?*`](http://msdn.microsoft.com/en-us/library/aa365247%28VS.85%29#naming_conventions) along with trailing periods are reserved.

## Install

```
$ npm install filenamify
```

## Usage

```js
const filenamify = require('filenamify');

filenamify('<foo/bar>');
//=> 'foo!bar'

filenamify('foo:"bar"', {replacement: '🐴'});
//=> 'foo🐴bar'
```

## API

### filenamify(string, options?)

Convert a string to a valid filename.

### filenamify.path(path, options?)

Convert the filename in a path a valid filename and return the augmented path.

#### options

Type: `object`

##### replacement

Type: `string`\
Default: `'!'`

String to use as replacement for reserved filename characters.

Cannot contain: `<` `>` `:` `"` `/` `\` `|` `?` `*`

##### maxLength

Type: `number`\
Default: `100`

Truncate the filename to the given length.

Systems generally allow up to 255 characters, but we default to 100 for usability reasons.

## Browser-only import

You can also import `filenamify/browser`, which only imports `filenamify` and not `filenamify.path`, which relies on `path` being available or polyfilled. Importing `filenamify` this way is therefore useful when it is shipped using `webpack` or similar tools, and if `filenamify.path` is not needed.

```js
const filenamify = require('filenamify/browser');

filenamify('<foo/bar>');
//=> 'foo!bar'
```

## Related

- [filenamify-cli](https://github.com/sindresorhus/filenamify-cli) - CLI for this module
- [filenamify-url](https://github.com/sindresorhus/filenamify-url) - Convert a URL to a valid filename
- [valid-filename](https://github.com/sindresorhus/valid-filename) - Check if a string is a valid filename
- [unused-filename](https://github.com/sindresorhus/unused-filename) - Get a unused filename by appending a number if it exists
- [slugify](https://github.com/sindresorhus/slugify) - Slugify a string
