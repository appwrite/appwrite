declare namespace resolvePkg {
	interface Options {
		/**
		Directory to resolve from.

		@default process.cwd()
		*/
		readonly cwd?: string;
	}
}

/**
Resolve the path of a package regardless of it having an entry point.

@param moduleId - What you would use in `require()`.

@example
```
import resolvePkg = require('resolve-pkg');

// $ npm install --save-dev grunt-svgmin

resolvePkg('grunt-svgmin/tasks', {cwd: __dirname});
//=> '/Users/sindresorhus/unicorn/node_modules/grunt-svgmin/tasks'

// Fails here as Grunt tasks usually don't have a defined main entry point
require.resolve('grunt-svgmin/tasks');
//=> Error: Cannot find module 'grunt-svgmin'
```
*/
declare function resolvePkg(
	moduleId: string,
	options?: resolvePkg.Options
): string | undefined;

export = resolvePkg;
