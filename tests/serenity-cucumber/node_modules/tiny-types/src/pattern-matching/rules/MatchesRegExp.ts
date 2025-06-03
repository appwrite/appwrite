import { MatcherRule } from './MatcherRule';

/**
 * @access private
 */
export class MatchesRegExp<Output_Type> extends MatcherRule<string, Output_Type> {
    constructor(private readonly pattern: RegExp, transformation: (v: string) => Output_Type) {
        super(transformation);
    }

    matches(value: string): boolean {
        return this.pattern.test(value);
    }
}
