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
var CucumberExpressionError_js_1 = __importDefault(require("./CucumberExpressionError.js"));
var CucumberExpressionGenerator_js_1 = __importDefault(require("./CucumberExpressionGenerator.js"));
var defineDefaultParameterTypes_js_1 = __importDefault(require("./defineDefaultParameterTypes.js"));
var Errors_js_1 = require("./Errors.js");
var ParameterType_js_1 = __importDefault(require("./ParameterType.js"));
var ParameterTypeRegistry = /** @class */ (function () {
    function ParameterTypeRegistry() {
        this.parameterTypeByName = new Map();
        this.parameterTypesByRegexp = new Map();
        (0, defineDefaultParameterTypes_js_1.default)(this);
    }
    Object.defineProperty(ParameterTypeRegistry.prototype, "parameterTypes", {
        get: function () {
            return this.parameterTypeByName.values();
        },
        enumerable: false,
        configurable: true
    });
    ParameterTypeRegistry.prototype.lookupByTypeName = function (typeName) {
        return this.parameterTypeByName.get(typeName);
    };
    ParameterTypeRegistry.prototype.lookupByRegexp = function (parameterTypeRegexp, expressionRegexp, text) {
        var _this = this;
        var parameterTypes = this.parameterTypesByRegexp.get(parameterTypeRegexp);
        if (!parameterTypes) {
            return undefined;
        }
        if (parameterTypes.length > 1 && !parameterTypes[0].preferForRegexpMatch) {
            // We don't do this check on insertion because we only want to restrict
            // ambiguity when we look up by Regexp. Users of CucumberExpression should
            // not be restricted.
            var generatedExpressions = new CucumberExpressionGenerator_js_1.default(function () { return _this.parameterTypes; }).generateExpressions(text);
            throw Errors_js_1.AmbiguousParameterTypeError.forRegExp(parameterTypeRegexp, expressionRegexp, parameterTypes, generatedExpressions);
        }
        return parameterTypes[0];
    };
    ParameterTypeRegistry.prototype.defineParameterType = function (parameterType) {
        var e_1, _a;
        if (parameterType.name !== undefined) {
            if (this.parameterTypeByName.has(parameterType.name)) {
                if (parameterType.name.length === 0) {
                    throw new CucumberExpressionError_js_1.default("The anonymous parameter type has already been defined");
                }
                else {
                    throw new CucumberExpressionError_js_1.default("There is already a parameter type with name ".concat(parameterType.name));
                }
            }
            this.parameterTypeByName.set(parameterType.name, parameterType);
        }
        try {
            for (var _b = __values(parameterType.regexpStrings), _c = _b.next(); !_c.done; _c = _b.next()) {
                var parameterTypeRegexp = _c.value;
                if (!this.parameterTypesByRegexp.has(parameterTypeRegexp)) {
                    this.parameterTypesByRegexp.set(parameterTypeRegexp, []);
                }
                // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
                var parameterTypes = this.parameterTypesByRegexp.get(parameterTypeRegexp);
                var existingParameterType = parameterTypes[0];
                if (parameterTypes.length > 0 &&
                    existingParameterType.preferForRegexpMatch &&
                    parameterType.preferForRegexpMatch) {
                    throw new CucumberExpressionError_js_1.default('There can only be one preferential parameter type per regexp. ' +
                        "The regexp /".concat(parameterTypeRegexp, "/ is used for two preferential parameter types, {").concat(existingParameterType.name, "} and {").concat(parameterType.name, "}"));
                }
                if (parameterTypes.indexOf(parameterType) === -1) {
                    parameterTypes.push(parameterType);
                    this.parameterTypesByRegexp.set(parameterTypeRegexp, parameterTypes.sort(ParameterType_js_1.default.compare));
                }
            }
        }
        catch (e_1_1) { e_1 = { error: e_1_1 }; }
        finally {
            try {
                if (_c && !_c.done && (_a = _b.return)) _a.call(_b);
            }
            finally { if (e_1) throw e_1.error; }
        }
    };
    return ParameterTypeRegistry;
}());
exports.default = ParameterTypeRegistry;
//# sourceMappingURL=ParameterTypeRegistry.js.map