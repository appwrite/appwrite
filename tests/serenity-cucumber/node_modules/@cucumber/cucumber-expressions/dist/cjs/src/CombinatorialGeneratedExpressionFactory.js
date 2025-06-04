"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var GeneratedExpression_js_1 = __importDefault(require("./GeneratedExpression.js"));
// 256 generated expressions ought to be enough for anybody
var MAX_EXPRESSIONS = 256;
var CombinatorialGeneratedExpressionFactory = /** @class */ (function () {
    function CombinatorialGeneratedExpressionFactory(expressionTemplate, parameterTypeCombinations) {
        this.expressionTemplate = expressionTemplate;
        this.parameterTypeCombinations = parameterTypeCombinations;
        this.expressionTemplate = expressionTemplate;
    }
    CombinatorialGeneratedExpressionFactory.prototype.generateExpressions = function () {
        var generatedExpressions = [];
        this.generatePermutations(generatedExpressions, 0, []);
        return generatedExpressions;
    };
    CombinatorialGeneratedExpressionFactory.prototype.generatePermutations = function (generatedExpressions, depth, currentParameterTypes) {
        if (generatedExpressions.length >= MAX_EXPRESSIONS) {
            return;
        }
        if (depth === this.parameterTypeCombinations.length) {
            generatedExpressions.push(new GeneratedExpression_js_1.default(this.expressionTemplate, currentParameterTypes));
            return;
        }
        // tslint:disable-next-line:prefer-for-of
        for (var i = 0; i < this.parameterTypeCombinations[depth].length; ++i) {
            // Avoid recursion if no elements can be added.
            if (generatedExpressions.length >= MAX_EXPRESSIONS) {
                return;
            }
            var newCurrentParameterTypes = currentParameterTypes.slice(); // clone
            newCurrentParameterTypes.push(this.parameterTypeCombinations[depth][i]);
            this.generatePermutations(generatedExpressions, depth + 1, newCurrentParameterTypes);
        }
    };
    return CombinatorialGeneratedExpressionFactory;
}());
exports.default = CombinatorialGeneratedExpressionFactory;
//# sourceMappingURL=CombinatorialGeneratedExpressionFactory.js.map