import filenamify = require('./filenamify');
import filenamifyPath = require('./filenamify-path');

declare const filenamifyCombined: {
	/**
	Convert a string to a valid filename.

	@example
	```
	import filenamify = require('filenamify');

	filenamify('<foo/bar>');
	//=> 'foo!bar'

	filenamify('foo:"bar"', {replacement: '🐴'});
	//=> 'foo🐴bar'
	```
	*/
	(string: string, options?: filenamify.Options): string;

	path: typeof filenamifyPath;
};

export = filenamifyCombined;
