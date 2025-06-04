"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var Argument_js_1 = __importDefault(require("./Argument.js"));
var ParameterType_js_1 = __importDefault(require("./ParameterType.js"));
var TreeRegexp_js_1 = __importDefault(require("./TreeRegexp.js"));
var RegularExpression = /** @class */ (function () {
    function RegularExpression(regexp, parameterTypeRegistry) {
        this.regexp = regexp;
        this.parameterTypeRegistry = parameterTypeRegistry;
        this.treeRegexp = new TreeRegexp_js_1.default(regexp);
    }
    RegularExpression.prototype.match = function (text) {
        var _this = this;
        var group = this.treeRegexp.match(text);
        if (!group) {
            return null;
        }
        var parameterTypes = this.treeRegexp.groupBuilder.children.map(function (groupBuilder) {
            var parameterTypeRegexp = groupBuilder.source;
            var parameterType = _this.parameterTypeRegistry.lookupByRegexp(parameterTypeRegexp, _this.regexp, text);
            return (parameterType ||
                new ParameterType_js_1.default(undefined, parameterTypeRegexp, String, function (s) { return (s === undefined ? null : s); }, false, false));
        });
        return Argument_js_1.default.build(group, parameterTypes);
    };
    Object.defineProperty(RegularExpression.prototype, "source", {
        get: function () {
            return this.regexp.source;
        },
        enumerable: false,
        configurable: true
    });
    return RegularExpression;
}());
exports.default = RegularExpression;
//# sourceMappingURL=RegularExpression.js.map