"use strict";
var __values = (this && this.__values) || function(o) {
    var s = typeof Symbol === "function" && Symbol.iterator, m = s && o[s], i = 0;
    if (m) return m.call(o);
    if (o && typeof o.length === "number") return {
        next: function () {
            if (o && i >= o.length) o = void 0;
            return { value: o && o[i++], done: !o };
        }
    };
    throw new TypeError(s ? "Object is not iterable." : "Symbol.iterator is not defined.");
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var assert_1 = __importDefault(require("assert"));
var fs_1 = __importDefault(require("fs"));
var glob_1 = __importDefault(require("glob"));
var js_yaml_1 = __importDefault(require("js-yaml"));
var ParameterTypeRegistry_js_1 = __importDefault(require("../src/ParameterTypeRegistry.js"));
var RegularExpression_js_1 = __importDefault(require("../src/RegularExpression.js"));
var testDataDir_js_1 = require("./testDataDir.js");
describe('RegularExpression', function () {
    var e_1, _a;
    var _loop_1 = function (path) {
        var expectation = js_yaml_1.default.load(fs_1.default.readFileSync(path, 'utf-8'));
        it("matches ".concat(path), function () {
            var parameterTypeRegistry = new ParameterTypeRegistry_js_1.default();
            var expression = new RegularExpression_js_1.default(new RegExp(expectation.expression), parameterTypeRegistry);
            var matches = expression.match(expectation.text);
            assert_1.default.deepStrictEqual(JSON.parse(JSON.stringify(matches ? matches.map(function (value) { return value.getValue(null); }) : null)), // Removes type information.
            expectation.expected_args);
        });
    };
    try {
        for (var _b = __values(glob_1.default.sync("".concat(testDataDir_js_1.testDataDir, "/regular-expression/matching/*.yaml"))), _c = _b.next(); !_c.done; _c = _b.next()) {
            var path = _c.value;
            _loop_1(path);
        }
    }
    catch (e_1_1) { e_1 = { error: e_1_1 }; }
    finally {
        try {
            if (_c && !_c.done && (_a = _b.return)) _a.call(_b);
        }
        finally { if (e_1) throw e_1.error; }
    }
    it('does no transform by default', function () {
        assert_1.default.deepStrictEqual(match(/(\d\d)/, '22'), ['22']);
    });
    it('does not transform anonymous', function () {
        assert_1.default.deepStrictEqual(match(/(.*)/, '22'), ['22']);
    });
    it('transforms negative int', function () {
        assert_1.default.deepStrictEqual(match(/(-?\d+)/, '-22'), [-22]);
    });
    it('transforms positive int', function () {
        assert_1.default.deepStrictEqual(match(/(\d+)/, '22'), [22]);
    });
    it('returns null when there is no match', function () {
        assert_1.default.strictEqual(match(/hello/, 'world'), null);
    });
    it('matches empty string', function () {
        assert_1.default.deepStrictEqual(match(/^The value equals "([^"]*)"$/, 'The value equals ""'), ['']);
    });
    it('matches nested capture group without match', function () {
        assert_1.default.deepStrictEqual(match(/^a user( named "([^"]*)")?$/, 'a user'), [null]);
    });
    it('matches nested capture group with match', function () {
        assert_1.default.deepStrictEqual(match(/^a user( named "([^"]*)")?$/, 'a user named "Charlie"'), [
            'Charlie',
        ]);
    });
    it('matches capture group nested in optional one', function () {
        var regexp = /^a (pre-commercial transaction |pre buyer fee model )?purchase(?: for \$(\d+))?$/;
        assert_1.default.deepStrictEqual(match(regexp, 'a purchase'), [null, null]);
        assert_1.default.deepStrictEqual(match(regexp, 'a purchase for $33'), [null, 33]);
        assert_1.default.deepStrictEqual(match(regexp, 'a pre buyer fee model purchase'), [
            'pre buyer fee model ',
            null,
        ]);
    });
    it('ignores non capturing groups', function () {
        assert_1.default.deepStrictEqual(match(/(\S+) ?(can|cannot)? (?:delete|cancel) the (\d+)(?:st|nd|rd|th) (attachment|slide) ?(?:upload)?/, 'I can cancel the 1st slide upload'), ['I', 'can', 1, 'slide']);
    });
    it('works with escaped parenthesis', function () {
        assert_1.default.deepStrictEqual(match(/Across the line\(s\)/, 'Across the line(s)'), []);
    });
    it('exposes regexp and source', function () {
        var regexp = /I have (\d+) cukes? in my (.+) now/;
        var expression = new RegularExpression_js_1.default(regexp, new ParameterTypeRegistry_js_1.default());
        assert_1.default.deepStrictEqual(expression.regexp, regexp);
        assert_1.default.deepStrictEqual(expression.source, regexp.source);
    });
    it('does not take consider parenthesis in character class as group', function () {
        var expression = new RegularExpression_js_1.default(/^drawings: ([A-Z_, ()]+)$/, new ParameterTypeRegistry_js_1.default());
        var args = expression.match('drawings: ONE, TWO(ABC)');
        assert_1.default.strictEqual(args[0].getValue(this), 'ONE, TWO(ABC)');
    });
});
var match = function (regexp, text) {
    var parameterRegistry = new ParameterTypeRegistry_js_1.default();
    var regularExpression = new RegularExpression_js_1.default(regexp, parameterRegistry);
    var args = regularExpression.match(text);
    if (!args) {
        return null;
    }
    return args.map(function (arg) { return arg.getValue(null); });
};
//# sourceMappingURL=RegularExpressionTest.js.map