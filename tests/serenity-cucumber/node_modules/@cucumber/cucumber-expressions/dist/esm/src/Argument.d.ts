import Group from './Group.js';
import ParameterType from './ParameterType.js';
export default class Argument {
    readonly group: Group;
    readonly parameterType: ParameterType<unknown>;
    static build(group: Group, parameterTypes: readonly ParameterType<unknown>[]): readonly Argument[];
    constructor(group: Group, parameterType: ParameterType<unknown>);
    /**
     * Get the value returned by the parameter type's transformer function.
     *
     * @param thisObj the object in which the transformer function is applied.
     */
    getValue<T>(thisObj: unknown): T | null;
    getParameterType(): ParameterType<unknown>;
}
//# sourceMappingURL=Argument.d.ts.map