import { TinyType } from '../TinyType';
import { ConstructorAbstractOrInstance } from '../types';
import { PatternMatcher } from './PatternMatcher';
import { MatcherRule, MatchesEqualTinyType, MatchesIdentical, MatchesObjectsWithCommonPrototype } from './rules';

/**
 * @access private
 */
export class ObjectMatcher<Input_Type, Output_Type> extends PatternMatcher<Input_Type, TinyType | ConstructorAbstractOrInstance<Input_Type>, TinyType | Input_Type, Output_Type> {

    when<MT extends Input_Type>(pattern: ConstructorAbstractOrInstance<MT>, transformation: (v: MT) => Output_Type): ObjectMatcher<Input_Type, Output_Type>;
    when(pattern: TinyType, transformation: (v: TinyType) => Output_Type): ObjectMatcher<Input_Type, Output_Type>;
    when(pattern: Input_Type, transformation: (v: Input_Type) => Output_Type): ObjectMatcher<Input_Type, Output_Type>;
    when(pattern: any, transformation: (v: any) => Output_Type) {   // eslint-disable-line @typescript-eslint/explicit-module-boundary-types
        return new ObjectMatcher(
            this.value,
            this.rules.concat(this.rule(pattern, transformation)),
        );
    }

    private rule(pattern: any, transformation: (v: any) => Output_Type): MatcherRule<any, Output_Type> {
        switch (true) {
            case pattern instanceof TinyType:
                return new MatchesEqualTinyType<Output_Type>(pattern as TinyType, transformation);
            case typeof pattern === 'function':
                return new MatchesObjectsWithCommonPrototype<any, Output_Type>(pattern, transformation);
            default:
                return new MatchesIdentical<Input_Type, Output_Type>(pattern, transformation);
        }
    }
}
