import { PatternMatcher } from './PatternMatcher';
import { MatchesIdentical } from './rules';

/**
 * @access private
 */
export class IdentityMatcher<Input_Type, Output_Type> extends PatternMatcher<Input_Type, Input_Type, Input_Type, Output_Type> {

    when(pattern: Input_Type, transformation: (v: Input_Type) => Output_Type): PatternMatcher<Input_Type, Input_Type, Input_Type, Output_Type> {
        return new IdentityMatcher(
            this.value,
            this.rules.concat(new MatchesIdentical(pattern, transformation)),
        );
    }
}
