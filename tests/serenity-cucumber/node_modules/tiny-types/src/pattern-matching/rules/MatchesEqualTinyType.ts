import { TinyType } from '../../TinyType';
import { MatcherRule } from './MatcherRule';

/**
 * @access private
 */
export class MatchesEqualTinyType<Output_Type> extends MatcherRule<TinyType, Output_Type> {
    constructor(private readonly pattern: TinyType, transformation: (v: TinyType) => Output_Type) {
        super(transformation);
    }

    matches(value: TinyType): boolean {
        return this.pattern.equals(value);
    }
}
