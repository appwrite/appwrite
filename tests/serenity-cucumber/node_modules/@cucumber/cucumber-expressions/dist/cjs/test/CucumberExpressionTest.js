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
var CucumberExpression_js_1 = __importDefault(require("../src/CucumberExpression.js"));
var CucumberExpressionError_js_1 = __importDefault(require("../src/CucumberExpressionError.js"));
var ParameterType_js_1 = __importDefault(require("../src/ParameterType.js"));
var ParameterTypeRegistry_js_1 = __importDefault(require("../src/ParameterTypeRegistry.js"));
var testDataDir_js_1 = require("./testDataDir.js");
describe('CucumberExpression', function () {
    var e_1, _a;
    var _loop_1 = function (path) {
        var expectation = js_yaml_1.default.load(fs_1.default.readFileSync(path, 'utf-8'));
        it("matches ".concat(path), function () {
            var parameterTypeRegistry = new ParameterTypeRegistry_js_1.default();
            if (expectation.expected_args !== undefined) {
                var expression = new CucumberExpression_js_1.default(expectation.expression, parameterTypeRegistry);
                var matches = expression.match(expectation.text);
                assert_1.default.deepStrictEqual(JSON.parse(JSON.stringify(matches ? matches.map(function (value) { return value.getValue(null); }) : null, function (key, value) {
                    return typeof value === 'bigint' ? value.toString() : value;
                })), // Removes type information.
                expectation.expected_args);
            }
            else if (expectation.exception !== undefined) {
                assert_1.default.throws(function () {
                    var expression = new CucumberExpression_js_1.default(expectation.expression, parameterTypeRegistry);
                    expression.match(expectation.text);
                }, new CucumberExpressionError_js_1.default(expectation.exception));
            }
            else {
                throw new Error("Expectation must have expected or exception: ".concat(JSON.stringify(expectation)));
            }
        });
    };
    try {
        for (var _b = __values(glob_1.default.sync("".concat(testDataDir_js_1.testDataDir, "/cucumber-expression/matching/*.yaml"))), _c = _b.next(); !_c.done; _c = _b.next()) {
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
    it('matches float', function () {
        assert_1.default.deepStrictEqual(match('{float}', ''), null);
        assert_1.default.deepStrictEqual(match('{float}', '.'), null);
        assert_1.default.deepStrictEqual(match('{float}', ','), null);
        assert_1.default.deepStrictEqual(match('{float}', '-'), null);
        assert_1.default.deepStrictEqual(match('{float}', 'E'), null);
        assert_1.default.deepStrictEqual(match('{float}', '1,'), null);
        assert_1.default.deepStrictEqual(match('{float}', ',1'), null);
        assert_1.default.deepStrictEqual(match('{float}', '1.'), null);
        assert_1.default.deepStrictEqual(match('{float}', '1'), [1]);
        assert_1.default.deepStrictEqual(match('{float}', '-1'), [-1]);
        assert_1.default.deepStrictEqual(match('{float}', '1.1'), [1.1]);
        assert_1.default.deepStrictEqual(match('{float}', '1,000'), null);
        assert_1.default.deepStrictEqual(match('{float}', '1,000,0'), null);
        assert_1.default.deepStrictEqual(match('{float}', '1,000.1'), null);
        assert_1.default.deepStrictEqual(match('{float}', '1,000,10'), null);
        assert_1.default.deepStrictEqual(match('{float}', '1,0.1'), null);
        assert_1.default.deepStrictEqual(match('{float}', '1,000,000.1'), null);
        assert_1.default.deepStrictEqual(match('{float}', '-1.1'), [-1.1]);
        assert_1.default.deepStrictEqual(match('{float}', '.1'), [0.1]);
        assert_1.default.deepStrictEqual(match('{float}', '-.1'), [-0.1]);
        assert_1.default.deepStrictEqual(match('{float}', '-.10000001'), [-0.10000001]);
        assert_1.default.deepStrictEqual(match('{float}', '1E1'), [1e1]); // precision 1 with scale -1, can not be expressed as a decimal
        assert_1.default.deepStrictEqual(match('{float}', '.1E1'), [1]);
        assert_1.default.deepStrictEqual(match('{float}', 'E1'), null);
        assert_1.default.deepStrictEqual(match('{float}', '-.1E-1'), [-0.01]);
        assert_1.default.deepStrictEqual(match('{float}', '-.1E-2'), [-0.001]);
        assert_1.default.deepStrictEqual(match('{float}', '-.1E+1'), [-1]);
        assert_1.default.deepStrictEqual(match('{float}', '-.1E+2'), [-10]);
        assert_1.default.deepStrictEqual(match('{float}', '-.1E1'), [-1]);
        assert_1.default.deepStrictEqual(match('{float}', '-.10E2'), [-10]);
    });
    it('matches float with zero', function () {
        assert_1.default.deepEqual(match('{float}', '0'), [0]);
    });
    it('exposes source', function () {
        var expr = 'I have {int} cuke(s)';
        assert_1.default.strictEqual(new CucumberExpression_js_1.default(expr, new ParameterTypeRegistry_js_1.default()).source, expr);
    });
    it('unmatched optional groups have undefined values', function () {
        var parameterTypeRegistry = new ParameterTypeRegistry_js_1.default();
        parameterTypeRegistry.defineParameterType(new ParameterType_js_1.default('textAndOrNumber', /([A-Z]+)?(?: )?([0-9]+)?/, null, function (s1, s2) {
            return [s1, s2];
        }, false, true));
        var expression = new CucumberExpression_js_1.default('{textAndOrNumber}', parameterTypeRegistry);
        var world = {};
        assert_1.default.deepStrictEqual(expression.match("TLA")[0].getValue(world), ['TLA', undefined]);
        assert_1.default.deepStrictEqual(expression.match("123")[0].getValue(world), [undefined, '123']);
    });
    // JavaScript-specific
    it('delegates transform to custom object', function () {
        var parameterTypeRegistry = new ParameterTypeRegistry_js_1.default();
        parameterTypeRegistry.defineParameterType(new ParameterType_js_1.default('widget', /\w+/, null, function (s) {
            return this.createWidget(s);
        }, false, true));
        var expression = new CucumberExpression_js_1.default('I have a {widget}', parameterTypeRegistry);
        var world = {
            createWidget: function (s) {
                return "widget:".concat(s);
            },
        };
        var args = expression.match("I have a bolt");
        assert_1.default.strictEqual(args[0].getValue(world), 'widget:bolt');
    });
});
var match = function (expression, text) {
    var cucumberExpression = new CucumberExpression_js_1.default(expression, new ParameterTypeRegistry_js_1.default());
    var args = cucumberExpression.match(text);
    if (!args) {
        return null;
    }
    return args.map(function (arg) { return arg.getValue(null); });
};
//# sourceMappingURL=CucumberExpressionTest.js.map