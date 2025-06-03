import Argument from './Argument.js';
import ParameterTypeRegistry from './ParameterTypeRegistry.js';
import { Expression } from './types.js';
export default class RegularExpression implements Expression {
    readonly regexp: RegExp;
    private readonly parameterTypeRegistry;
    private readonly treeRegexp;
    constructor(regexp: RegExp, parameterTypeRegistry: ParameterTypeRegistry);
    match(text: string): readonly Argument[] | null;
    get source(): string;
}
//# sourceMappingURL=RegularExpression.d.ts.map