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
var js_yaml_1 = __importDefault(require("js-yaml"));
var index_js_1 = __importDefault(require("../src/index.js"));
var testDataDir_js_1 = require("./testDataDir.js");
var tests = js_yaml_1.default.load(fs_1.default.readFileSync("".concat(testDataDir_js_1.testDataDir, "/parsing.yml"), 'utf-8'));
describe('Parsing', function () {
    var e_1, _a;
    var _loop_1 = function (test_1) {
        it("parses \"".concat(test_1.expression, "\" into \"").concat(test_1.formatted, "\""), function () {
            var expression = (0, index_js_1.default)(test_1.expression);
            assert_1.default.strictEqual(expression.toString(), test_1.formatted);
            var expressionAgain = (0, index_js_1.default)(expression.toString());
            assert_1.default.strictEqual(expressionAgain.toString(), test_1.formatted);
        });
    };
    try {
        for (var tests_1 = __values(tests), tests_1_1 = tests_1.next(); !tests_1_1.done; tests_1_1 = tests_1.next()) {
            var test_1 = tests_1_1.value;
            _loop_1(test_1);
        }
    }
    catch (e_1_1) { e_1 = { error: e_1_1 }; }
    finally {
        try {
            if (tests_1_1 && !tests_1_1.done && (_a = tests_1.return)) _a.call(tests_1);
        }
        finally { if (e_1) throw e_1.error; }
    }
});
//# sourceMappingURL=parsing.test.js.map