"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var assert_1 = __importDefault(require("assert"));
var detectCiEnvironment_js_1 = require("../src/detectCiEnvironment.js");
describe('removeUserInfoFromUrl', function () {
    it('returns empty string for empty string', function () {
        assert_1.default.strictEqual((0, detectCiEnvironment_js_1.removeUserInfoFromUrl)(''), '');
    });
    it('leaves the data intact when no sensitive information is detected', function () {
        assert_1.default.strictEqual((0, detectCiEnvironment_js_1.removeUserInfoFromUrl)('pretty safe'), 'pretty safe');
    });
    context('with URLs', function () {
        it('leaves intact when no password is found', function () {
            assert_1.default.strictEqual((0, detectCiEnvironment_js_1.removeUserInfoFromUrl)('https://example.com/git/repo.git'), 'https://example.com/git/repo.git');
        });
        it('removes credentials when found', function () {
            assert_1.default.strictEqual((0, detectCiEnvironment_js_1.removeUserInfoFromUrl)('http://aslak@example.com/git/repo.git'), 'http://example.com/git/repo.git');
        });
        it('removes credentials and passwords when found', function () {
            assert_1.default.strictEqual((0, detectCiEnvironment_js_1.removeUserInfoFromUrl)('ssh://login:password@example.com/git/repo.git'), 'ssh://example.com/git/repo.git');
        });
    });
});
//# sourceMappingURL=removeUserInfoFromUrlTest.js.map