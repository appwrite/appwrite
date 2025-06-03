import Argument from './Argument.js';
import ParameterType from './ParameterType';
export interface DefinesParameterType {
    defineParameterType<T>(parameterType: ParameterType<T>): void;
}
export interface Expression {
    readonly source: string;
    match(text: string): readonly Argument[] | null;
}
export type ParameterInfo = {
    /**
     * The string representation of the original ParameterType#type property
     */
    type: string | null;
    /**
     * The parameter type name
     */
    name: string;
    /**
     * The number of times this name has been used so far
     */
    count: number;
};
//# sourceMappingURL=types.d.ts.map