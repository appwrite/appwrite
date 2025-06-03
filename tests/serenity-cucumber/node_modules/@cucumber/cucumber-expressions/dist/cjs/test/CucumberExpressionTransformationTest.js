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
var ParameterTypeRegistry_js_1 = __importDefault(require("../src/ParameterTypeRegistry.js"));
var testDataDir_js_1 = require("./testDataDir.js");
describe('CucumberExpression', function () {
    var e_1, _a;
    var _loop_1 = function (path) {
        var expectation = js_yaml_1.default.load(fs_1.default.readFileSync(path, 'utf-8'));
        it("transforms ".concat(path), function () {
            var parameterTypeRegistry = new ParameterTypeRegistry_js_1.default();
            var expression = new CucumberExpression_js_1.default(expectation.expression, parameterTypeRegistry);
            assert_1.default.deepStrictEqual(expression.regexp.source, expectation.expected_regex);
        });
    };
    try {
        for (var _b = __values(glob_1.default.sync("".concat(testDataDir_js_1.testDataDir, "/cucumber-expression/transformation/*.yaml"))), _c = _b.next(); !_c.done; _c = _b.next()) {
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
});
//# sourceMappingURL=CucumberExpressionTransformationTest.js.map