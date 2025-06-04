import ParameterType from './ParameterType.js';
import { ParameterInfo } from './types.js';
export default class GeneratedExpression {
    private readonly expressionTemplate;
    readonly parameterTypes: readonly ParameterType<unknown>[];
    constructor(expressionTemplate: string, parameterTypes: readonly ParameterType<unknown>[]);
    get source(): string;
    /**
     * Returns an array of parameter names to use in generated function/method signatures
     *
     * @returns {ReadonlyArray.<String>}
     */
    get parameterNames(): readonly string[];
    /**
     * Returns an array of ParameterInfo to use in generated function/method signatures
     */
    get parameterInfos(): readonly ParameterInfo[];
}
//# sourceMappingURL=GeneratedExpression.d.ts.map