/*
 * tst.invalid.js: tests invalid invocations
 */

var mod_assert = require('assert');
var mod_extsprintf = require('../lib/extsprintf');
var mod_path = require('path');
var sprintf = mod_extsprintf.sprintf;

var testcases = [ {
    'name': 'missing all arguments',
    'args': [],
    'errmsg': /first argument must be a format string$/
}, {
    'name': 'missing argument for format specifier (first char and specifier)',
    'args': [ '%s' ],
    'errmsg': new RegExp(
        'format string "%s": conversion specifier "%s" at character 1 ' +
	'has no matching argument \\(too few arguments passed\\)')
}, {
    'name': 'missing argument for format specifier (later in string)',
    'args': [ 'hello %s world %13d', 'big' ],
    'errmsg': new RegExp(
        'format string "hello %s world %13d": conversion specifier "%13d" at ' +
	'character 16 has no matching argument \\(too few arguments passed\\)')
}, {
    'name': 'printing null as string',
    'args': [ '%d cookies %3s', 15, null ],
    'errmsg': new RegExp(
        'format string "%d cookies %3s": conversion specifier "%3s" at ' +
	'character 12 attempted to print undefined or null as a string ' +
	'\\(argument 3 to sprintf\\)')
}, {
    'name': 'printing undefined as string',
    'args': [ '%d cookies %3s ah %d', 15, undefined, 7 ],
    'errmsg': new RegExp(
        'format string "%d cookies %3s ah %d": conversion specifier "%3s" at ' +
	'character 12 attempted to print undefined or null as a string ' +
	'\\(argument 3 to sprintf\\)')
}, {
    'name': 'unsupported format character',
    'args': [ 'do not use %X', 13 ],
    'errmsg': new RegExp(
        'format string "do not use %X": conversion ' +
	'specifier "%X" at character 12 is not supported$')
}, {
    'name': 'unsupported flags',
    'args': [ '%#x', 13 ],
    'errmsg': new RegExp(
        'format string "%#x": conversion ' +
	'specifier "%#x" at character 1 uses unsupported flags$')
} ];

function main(verbose) {
	testcases.forEach(function (tc) {
		var error;
		console.error('test case: %s', tc.name);
		if (verbose) {
			console.error('    args:   %s', JSON.stringify(tc.args));
		}
		mod_assert.throws(function () {
			try {
				sprintf.apply(null, tc.args);
			} catch (ex) {
				error = ex;
				throw (ex);
			}
		}, tc.errmsg);

		if (verbose && error) {
			console.error('    error:  %s', error.message);
		}
	});

	console.log('%s tests passed', mod_path.basename(__filename));
}

main(process.argv.length > 2 && process.argv[2] == '-v');
