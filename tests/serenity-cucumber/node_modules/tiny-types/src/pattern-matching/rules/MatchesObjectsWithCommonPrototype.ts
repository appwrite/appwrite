import { ConstructorOrAbstract } from '../../types';
import { MatcherRule } from './MatcherRule';

/**
 * @access private
 */
export class MatchesObjectsWithCommonPrototype<Input_Type, Output_Type> extends MatcherRule<Input_Type, Output_Type> {
    constructor(
        private readonly pattern: ConstructorOrAbstract<Input_Type>,
        transformation: (v: Input_Type) => Output_Type,
    ) {
        super(transformation);
    }

    matches(value: Input_Type): boolean {
        return value instanceof this.pattern;
    }
}
