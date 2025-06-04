import Argument from './Argument.js';
import { Node } from './Ast.js';
import ParameterTypeRegistry from './ParameterTypeRegistry.js';
import { Expression } from './types.js';
export default class CucumberExpression implements Expression {
    private readonly expression;
    private readonly parameterTypeRegistry;
    private readonly parameterTypes;
    private readonly treeRegexp;
    readonly ast: Node;
    /**
     * @param expression
     * @param parameterTypeRegistry
     */
    constructor(expression: string, parameterTypeRegistry: ParameterTypeRegistry);
    private rewriteToRegex;
    private static escapeRegex;
    private rewriteOptional;
    private rewriteAlternation;
    private rewriteAlternative;
    private rewriteParameter;
    private rewriteExpression;
    private assertNotEmpty;
    private assertNoParameters;
    private assertNoOptionals;
    match(text: string): readonly Argument[] | null;
    get regexp(): RegExp;
    get source(): string;
}
//# sourceMappingURL=CucumberExpression.d.ts.map