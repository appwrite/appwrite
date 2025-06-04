"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var CucumberExpressionError_js_1 = __importDefault(require("./CucumberExpressionError.js"));
var Argument = /** @class */ (function () {
    function Argument(group, parameterType) {
        this.group = group;
        this.parameterType = parameterType;
        this.group = group;
        this.parameterType = parameterType;
    }
    Argument.build = function (group, parameterTypes) {
        var argGroups = group.children;
        if (argGroups.length !== parameterTypes.length) {
            throw new CucumberExpressionError_js_1.default("Group has ".concat(argGroups.length, " capture groups (").concat(argGroups.map(function (g) { return g.value; }), "), but there were ").concat(parameterTypes.length, " parameter types (").concat(parameterTypes.map(function (p) { return p.name; }), ")"));
        }
        return parameterTypes.map(function (parameterType, i) { return new Argument(argGroups[i], parameterType); });
    };
    /**
     * Get the value returned by the parameter type's transformer function.
     *
     * @param thisObj the object in which the transformer function is applied.
     */
    Argument.prototype.getValue = function (thisObj) {
        var groupValues = this.group ? this.group.values : null;
        return this.parameterType.transform(thisObj, groupValues);
    };
    Argument.prototype.getParameterType = function () {
        return this.parameterType;
    };
    return Argument;
}());
exports.default = Argument;
//# sourceMappingURL=Argument.js.map