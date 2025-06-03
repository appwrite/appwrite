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
var path_1 = __importDefault(require("path"));
var index_js_1 = __importDefault(require("../src/index.js"));
describe('detectCiEnvironment', function () {
    var e_1, _a;
    var _loop_1 = function (txt) {
        it("detects ".concat(path_1.default.basename(txt, '.txt')), function () {
            var envData = fs_1.default.readFileSync(txt, { encoding: 'utf8' });
            var entries = envData.split(/\n/).map(function (line) { return line.split(/=/); });
            var env = Object.fromEntries(entries);
            var ciEnvironment = (0, index_js_1.default)(env);
            var expectedJson = fs_1.default.readFileSync("".concat(txt, ".json"), {
                encoding: 'utf8',
            });
            assert_1.default.deepStrictEqual(ciEnvironment, JSON.parse(expectedJson));
        });
    };
    try {
        for (var _b = __values(glob_1.default.sync("../testdata/*.txt")), _c = _b.next(); !_c.done; _c = _b.next()) {
            var txt = _c.value;
            _loop_1(txt);
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
//# sourceMappingURL=acceptanceTest.js.map