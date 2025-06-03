import ParameterTypeRegistry from './ParameterTypeRegistry.js';
import { Expression } from './types.js';
export default class ExpressionFactory {
    private readonly parameterTypeRegistry;
    constructor(parameterTypeRegistry: ParameterTypeRegistry);
    createExpression(expression: string | RegExp): Expression;
}
//# sourceMappingURL=ExpressionFactory.d.ts.map