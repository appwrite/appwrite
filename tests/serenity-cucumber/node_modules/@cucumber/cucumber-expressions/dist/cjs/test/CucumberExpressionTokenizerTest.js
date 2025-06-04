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
var CucumberExpressionError_js_1 = __importDefault(require("../src/CucumberExpressionError.js"));
var CucumberExpressionTokenizer_js_1 = __importDefault(require("../src/CucumberExpressionTokenizer.js"));
var testDataDir_js_1 = require("./testDataDir.js");
describe('CucumberExpressionTokenizer', function () {
    var e_1, _a;
    var _loop_1 = function (path) {
        var expectation = js_yaml_1.default.load(fs_1.default.readFileSync(path, 'utf-8'));
        it("tokenizes ".concat(path), function () {
            var tokenizer = new CucumberExpressionTokenizer_js_1.default();
            if (expectation.expected_tokens !== undefined) {
                var tokens = tokenizer.tokenize(expectation.expression);
                assert_1.default.deepStrictEqual(JSON.parse(JSON.stringify(tokens)), // Removes type information.
                expectation.expected_tokens);
            }
            else if (expectation.exception !== undefined) {
                assert_1.default.throws(function () {
                    tokenizer.tokenize(expectation.expression);
                }, new CucumberExpressionError_js_1.default(expectation.exception));
            }
            else {
                throw new Error("Expectation must have expected_tokens or exception: ".concat(JSON.stringify(expectation)));
            }
        });
    };
    try {
        for (var _b = __values(glob_1.default.sync("".concat(testDataDir_js_1.testDataDir, "/cucumber-expression/tokenizer/*.yaml"))), _c = _b.next(); !_c.done; _c = _b.next()) {
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
//# sourceMappingURL=CucumberExpressionTokenizerTest.js.map