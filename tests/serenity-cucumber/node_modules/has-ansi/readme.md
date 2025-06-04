# has-ansi

> Check if a string has [ANSI escape codes](https://en.wikipedia.org/wiki/ANSI_escape_code)


## Install

```
$ npm install has-ansi
```


## Usage

```js
const hasAnsi = require('has-ansi');

hasAnsi('\u001B[4mUnicorn\u001B[0m');
//=> true

hasAnsi('cake');
//=> false
```


## Related

- [has-ansi-cli](https://github.com/chalk/has-ansi-cli) - CLI for this module
- [strip-ansi](https://github.com/chalk/strip-ansi) - Strip ANSI escape codes
- [ansi-regex](https://github.com/chalk/ansi-regex) - Regular expression for matching ANSI escape codes
- [chalk](https://github.com/chalk/chalk) - Terminal string styling done right


## Maintainers

- [Sindre Sorhus](https://github.com/sindresorhus)
- [Josh Junon](https://github.com/qix-)


---

<div align="center">
	<b>
		<a href="https://tidelift.com/subscription/pkg/npm-has-ansi?utm_source=npm-has-ansi&utm_medium=referral&utm_campaign=readme">Get professional support for this package with a Tidelift subscription</a>
	</b>
	<br>
	<sub>
		Tidelift helps make open source sustainable for maintainers while giving companies<br>assurances about security, maintenance, and licensing for their dependencies.
	</sub>
</div>
