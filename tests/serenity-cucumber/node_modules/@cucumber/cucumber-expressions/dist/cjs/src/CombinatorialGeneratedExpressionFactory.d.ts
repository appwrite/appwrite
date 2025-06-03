import GeneratedExpression from './GeneratedExpression.js';
import ParameterType from './ParameterType.js';
export default class CombinatorialGeneratedExpressionFactory {
    private readonly expressionTemplate;
    private readonly parameterTypeCombinations;
    constructor(expressionTemplate: string, parameterTypeCombinations: Array<Array<ParameterType<unknown>>>);
    generateExpressions(): readonly GeneratedExpression[];
    private generatePermutations;
}
//# sourceMappingURL=CombinatorialGeneratedExpressionFactory.d.ts.map