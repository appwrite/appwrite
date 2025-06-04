import { MatcherRule } from './MatcherRule';

/**
 * @access private
 */
export class MatchesIdentical<Input_Type, Output_Type> extends MatcherRule<Input_Type, Output_Type> {
    constructor(private readonly pattern: Input_Type, transformation: (v: Input_Type) => Output_Type) {
        super(transformation);
    }

    matches(value: Input_Type): boolean {
        return value === this.pattern;
    }
}
