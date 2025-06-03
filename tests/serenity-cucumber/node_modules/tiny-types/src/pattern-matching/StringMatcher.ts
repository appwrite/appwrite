import { PatternMatcher } from './PatternMatcher';
import { MatchesIdentical, MatchesRegExp } from './rules';

/**
 * @access private
 */
export class StringMatcher<Output_Type> extends PatternMatcher<string, string | RegExp, string, Output_Type> {

    when(pattern: string | RegExp, transformation: (v: string) => Output_Type): PatternMatcher<string, string | RegExp, string, Output_Type> {
        const rule = pattern instanceof RegExp
            ? new MatchesRegExp(pattern, transformation)
            : new MatchesIdentical(pattern, transformation);

        return new StringMatcher<Output_Type>(
            this.value,
            this.rules.concat(rule),
        );
    }
}
