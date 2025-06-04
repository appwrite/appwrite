"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var CucumberExpression_js_1 = __importDefault(require("./CucumberExpression.js"));
var RegularExpression_js_1 = __importDefault(require("./RegularExpression.js"));
var ExpressionFactory = /** @class */ (function () {
    function ExpressionFactory(parameterTypeRegistry) {
        this.parameterTypeRegistry = parameterTypeRegistry;
    }
    ExpressionFactory.prototype.createExpression = function (expression) {
        return typeof expression === 'string'
            ? new CucumberExpression_js_1.default(expression, this.parameterTypeRegistry)
            : new RegularExpression_js_1.default(expression, this.parameterTypeRegistry);
    };
    return ExpressionFactory;
}());
exports.default = ExpressionFactory;
//# sourceMappingURL=ExpressionFactory.js.map