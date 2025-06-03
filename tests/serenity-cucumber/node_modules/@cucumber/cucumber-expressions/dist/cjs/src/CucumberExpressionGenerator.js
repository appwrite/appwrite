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
var CombinatorialGeneratedExpressionFactory_js_1 = __importDefault(require("./CombinatorialGeneratedExpressionFactory.js"));
var ParameterType_js_1 = __importDefault(require("./ParameterType.js"));
var ParameterTypeMatcher_js_1 = __importDefault(require("./ParameterTypeMatcher.js"));
var CucumberExpressionGenerator = /** @class */ (function () {
    function CucumberExpressionGenerator(parameterTypes) {
        this.parameterTypes = parameterTypes;
    }
    CucumberExpressionGenerator.prototype.generateExpressions = function (text) {
        var parameterTypeCombinations = [];
        var parameterTypeMatchers = this.createParameterTypeMatchers(text);
        var expressionTemplate = '';
        var pos = 0;
        var counter = 0;
        var _loop_1 = function () {
            var e_1, _a, e_2, _b;
            var matchingParameterTypeMatchers = [];
            try {
                for (var parameterTypeMatchers_1 = (e_1 = void 0, __values(parameterTypeMatchers)), parameterTypeMatchers_1_1 = parameterTypeMatchers_1.next(); !parameterTypeMatchers_1_1.done; parameterTypeMatchers_1_1 = parameterTypeMatchers_1.next()) {
                    var parameterTypeMatcher = parameterTypeMatchers_1_1.value;
                    var advancedParameterTypeMatcher = parameterTypeMatcher.advanceTo(pos);
                    if (advancedParameterTypeMatcher.find) {
                        matchingParameterTypeMatchers.push(advancedParameterTypeMatcher);
                    }
                }
            }
            catch (e_1_1) { e_1 = { error: e_1_1 }; }
            finally {
                try {
                    if (parameterTypeMatchers_1_1 && !parameterTypeMatchers_1_1.done && (_a = parameterTypeMatchers_1.return)) _a.call(parameterTypeMatchers_1);
                }
                finally { if (e_1) throw e_1.error; }
            }
            if (matchingParameterTypeMatchers.length > 0) {
                matchingParameterTypeMatchers = matchingParameterTypeMatchers.sort(ParameterTypeMatcher_js_1.default.compare);
                // Find all the best parameter type matchers, they are all candidates.
                var bestParameterTypeMatcher_1 = matchingParameterTypeMatchers[0];
                var bestParameterTypeMatchers = matchingParameterTypeMatchers.filter(function (m) { return ParameterTypeMatcher_js_1.default.compare(m, bestParameterTypeMatcher_1) === 0; });
                // Build a list of parameter types without duplicates. The reason there
                // might be duplicates is that some parameter types have more than one regexp,
                // which means multiple ParameterTypeMatcher objects will have a reference to the
                // same ParameterType.
                // We're sorting the list so preferential parameter types are listed first.
                // Users are most likely to want these, so they should be listed at the top.
                var parameterTypes = [];
                try {
                    for (var bestParameterTypeMatchers_1 = (e_2 = void 0, __values(bestParameterTypeMatchers)), bestParameterTypeMatchers_1_1 = bestParameterTypeMatchers_1.next(); !bestParameterTypeMatchers_1_1.done; bestParameterTypeMatchers_1_1 = bestParameterTypeMatchers_1.next()) {
                        var parameterTypeMatcher = bestParameterTypeMatchers_1_1.value;
                        if (parameterTypes.indexOf(parameterTypeMatcher.parameterType) === -1) {
                            parameterTypes.push(parameterTypeMatcher.parameterType);
                        }
                    }
                }
                catch (e_2_1) { e_2 = { error: e_2_1 }; }
                finally {
                    try {
                        if (bestParameterTypeMatchers_1_1 && !bestParameterTypeMatchers_1_1.done && (_b = bestParameterTypeMatchers_1.return)) _b.call(bestParameterTypeMatchers_1);
                    }
                    finally { if (e_2) throw e_2.error; }
                }
                parameterTypes = parameterTypes.sort(ParameterType_js_1.default.compare);
                parameterTypeCombinations.push(parameterTypes);
                expressionTemplate += escape(text.slice(pos, bestParameterTypeMatcher_1.start));
                expressionTemplate += "{{".concat(counter++, "}}");
                pos = bestParameterTypeMatcher_1.start + bestParameterTypeMatcher_1.group.length;
            }
            else {
                return "break";
            }
            if (pos >= text.length) {
                return "break";
            }
        };
        // eslint-disable-next-line no-constant-condition
        while (true) {
            var state_1 = _loop_1();
            if (state_1 === "break")
                break;
        }
        expressionTemplate += escape(text.slice(pos));
        return new CombinatorialGeneratedExpressionFactory_js_1.default(expressionTemplate, parameterTypeCombinations).generateExpressions();
    };
    CucumberExpressionGenerator.prototype.createParameterTypeMatchers = function (text) {
        var e_3, _a;
        var parameterMatchers = [];
        try {
            for (var _b = __values(this.parameterTypes()), _c = _b.next(); !_c.done; _c = _b.next()) {
                var parameterType = _c.value;
                if (parameterType.useForSnippets) {
                    parameterMatchers = parameterMatchers.concat(CucumberExpressionGenerator.createParameterTypeMatchers2(parameterType, text));
                }
            }
        }
        catch (e_3_1) { e_3 = { error: e_3_1 }; }
        finally {
            try {
                if (_c && !_c.done && (_a = _b.return)) _a.call(_b);
            }
            finally { if (e_3) throw e_3.error; }
        }
        return parameterMatchers;
    };
    CucumberExpressionGenerator.createParameterTypeMatchers2 = function (parameterType, text) {
        return parameterType.regexpStrings.map(function (regexp) { return new ParameterTypeMatcher_js_1.default(parameterType, regexp, text); });
    };
    return CucumberExpressionGenerator;
}());
exports.default = CucumberExpressionGenerator;
function escape(s) {
    return s.replace(/\(/g, '\\(').replace(/{/g, '\\{').replace(/\//g, '\\/');
}
//# sourceMappingURL=CucumberExpressionGenerator.js.map