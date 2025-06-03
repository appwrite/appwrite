import GeneratedExpression from './GeneratedExpression.js';
import ParameterType from './ParameterType.js';
export default class CucumberExpressionGenerator {
    private readonly parameterTypes;
    constructor(parameterTypes: () => Iterable<ParameterType<unknown>>);
    generateExpressions(text: string): readonly GeneratedExpression[];
    private createParameterTypeMatchers;
    private static createParameterTypeMatchers2;
}
//# sourceMappingURL=CucumberExpressionGenerator.d.ts.map