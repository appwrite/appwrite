# tiny-case

Extremely minimal string casing utilities that mimic most of lodash's casing behavior, e.g.
numbers are considered seperate "words".

```sh
npm i tiny-case
```

## Usage

```js
import {
  camelCase,
  pascalCase,
  snakeCase,
  kebabCase,
  titleCase,
  sentenceCase,
  words,
  upperFirst,
} from 'tiny-case'

words('hi-there john') // ['hi', 'there', 'john']
words('   1ApplePlease  ') // ['1', 'Apple', 'Please']
```
