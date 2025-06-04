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
var evaluationsTest = js_yaml_1.default.load(fs_1.default.readFileSync("".concat(testDataDir_js_1.testDataDir, "/evaluations.yml"), 'utf-8'));
describe('Evaluations', function () {
    var e_1, _a;
    var _loop_1 = function (evaluation) {
        describe(evaluation.expression, function () {
            var e_2, _a;
            var _loop_2 = function (test_1) {
                it("evaluates [".concat(test_1.variables.join(', '), "] to ").concat(test_1.result), function () {
                    var node = (0, index_js_1.default)(evaluation.expression);
                    assert_1.default.strictEqual(node.evaluate(test_1.variables), test_1.result);
                });
            };
            try {
                for (var _b = (e_2 = void 0, __values(evaluation.tests)), _c = _b.next(); !_c.done; _c = _b.next()) {
                    var test_1 = _c.value;
                    _loop_2(test_1);
                }
            }
            catch (e_2_1) { e_2 = { error: e_2_1 }; }
            finally {
                try {
                    if (_c && !_c.done && (_a = _b.return)) _a.call(_b);
                }
                finally { if (e_2) throw e_2.error; }
            }
        });
    };
    try {
        for (var evaluationsTest_1 = __values(evaluationsTest), evaluationsTest_1_1 = evaluationsTest_1.next(); !evaluationsTest_1_1.done; evaluationsTest_1_1 = evaluationsTest_1.next()) {
            var evaluation = evaluationsTest_1_1.value;
            _loop_1(evaluation);
        }
    }
    catch (e_1_1) { e_1 = { error: e_1_1 }; }
    finally {
        try {
            if (evaluationsTest_1_1 && !evaluationsTest_1_1.done && (_a = evaluationsTest_1.return)) _a.call(evaluationsTest_1);
        }
        finally { if (e_1) throw e_1.error; }
    }
});
//# sourceMappingURL=evaluations.test.js.map