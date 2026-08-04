const test = require('node:test');
const assert = require('node:assert/strict');

global.window = {};
global.URL = URL;
global.document = {};

require('../../app/views/install/installer/js/modules/validation.js');

const { isValidHostnameInput } = global.window.InstallerStepsValidation;

test('rejects hostname input with an empty port', () => {
    assert.equal(isValidHostnameInput('example.com:'), false);
});

test('accepts hostname input with a valid port', () => {
    assert.equal(isValidHostnameInput('example.com:8080'), true);
});
