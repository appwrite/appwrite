/**
 * @access private
 */
export abstract class MatcherRule<Input_Type, Output_Type>{
    constructor(
        private readonly transformation: (v: Input_Type) => Output_Type,
    ) {
    }

    abstract matches(value: Input_Type): boolean;

    execute(value: Input_Type): Output_Type {
        return this.transformation(value);
    }
}
