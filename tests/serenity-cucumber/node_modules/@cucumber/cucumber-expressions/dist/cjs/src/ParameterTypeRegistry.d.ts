import ParameterType from './ParameterType.js';
import { DefinesParameterType } from './types';
export default class ParameterTypeRegistry implements DefinesParameterType {
    private readonly parameterTypeByName;
    private readonly parameterTypesByRegexp;
    constructor();
    get parameterTypes(): IterableIterator<ParameterType<unknown>>;
    lookupByTypeName(typeName: string): ParameterType<unknown> | undefined;
    lookupByRegexp(parameterTypeRegexp: string, expressionRegexp: RegExp, text: string): ParameterType<unknown> | undefined;
    defineParameterType(parameterType: ParameterType<unknown>): void;
}
//# sourceMappingURL=ParameterTypeRegistry.d.ts.map