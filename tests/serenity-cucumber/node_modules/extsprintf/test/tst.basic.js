/*
 * tst.basic.js: tests various valid invocation
 */

var mod_assert = require('assert');
var mod_extsprintf = require('../lib/extsprintf');
var mod_path = require('path');
var sprintf = mod_extsprintf.sprintf;

var testcases = [ {
    'name': 'empty string',
    'args': [ '' ],
    'result': ''
}, {
    'name': '%s: basic',
    'args': [ '%s', 'foo' ],
    'result': 'foo'
}, {
    'name': '%s: not first',
    'args': [ 'hello %s\n', 'world' ],
    'result': 'hello world\n'
}, {
    'name': '%s: right-aligned',
    'args': [ 'hello %10s\n', 'world' ],
    'result': 'hello      world\n'
}, {
    'name': '%s: left-aligned',
    'args': [ 'hello %-10sagain\n', 'world' ],
    'result': 'hello world     again\n'
}, {
    'name': '%d: basic, positive',
    'args': [ '%d', 17 ],
    'result': '17'
}, {
    'name': '%d: basic, zero',
    'args': [ '%d', 0 ],
    'result': '0'
}, {
    'name': '%d: basic, floating point value',
    'args': [ '%d', 17.3 ],
    'result': '17'
}, {
    'name': '%d: basic, negative',
    'args': [ '%d', -3 ],
    'result': '-3'
}, {
    'name': '%d: right-aligned',
    'args': [ '%4d', 17 ],
    'result': '  17'
}, {
    'name': '%d: right-aligned, zero-padded',
    'args': [ '%04d', 17 ],
    'result': '0017'
}, {
    'name': '%d: left-aligned',
    'args': [ '%-4d', 17 ],
    'result': '17  '
}, {
    'name': '%x: basic',
    'args': [ '%x', 18],
    'result': '12'
}, {
    'name': '%x: zero-padded, right-aligned',
    'args': [ '%08x', 0xfeedface ],
    'result': 'feedface'
}, {
    'name': '%d: with plus sign',
    'args': [ '%+d', 17 ],
    'result': '+17'
}, {
    'name': '%f: basic',
    'args': [ '%f', 3.2 ],
    'result': '3.2'
}, {
    'name': '%f: right-aligned',
    'args': [ '%5f', 3.2 ],
    'result': '  3.2'
}, {
    'name': '%%: basic',
    'args': [ '%%' ],
    'result': '%'
}, {
    'name': 'complex',
    'args': [ 'one %s %8s %-3d bytes past 0x%04x, which was %6f%%%s%5s',
        'program', 'wrote', -2, 0x30, 3.7, ' plus', 'over' ],
    'result': 'one program    wrote -2  bytes past 0x0030, which was    ' +
        '3.7% plus over'
} ];

function main(verbose) {
	/*
	 * Create one test case with a very large input string.
	 */
	var input = '1234';
	while (input.length < 100 * 1024) {
		input += input;
	}
	testcases.push({
	    'name': 'long string argument (' + input.length + ' characters)',
	    'args': [ '%s', input ],
	    'result': input
	});

	testcases.forEach(function (tc) {
		var result;
		console.error('test case: %s', tc.name);
		result = sprintf.apply(null, tc.args);
		if (verbose) {
			console.error('    args:   %s', JSON.stringify(tc.args));
			console.error('    result: %s', result);
		}
		mod_assert.equal(tc.result, result);
	});

	console.log('%s tests passed', mod_path.basename(__filename));
}

main(process.argv.length > 2 && process.argv[2] == '-v');
