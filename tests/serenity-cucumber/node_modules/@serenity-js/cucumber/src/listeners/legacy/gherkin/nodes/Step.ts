import type { ASTNode } from './ASTNode';
import type { StepArgument } from './StepArgument';

/**
 * @private
 */
export interface Step extends ASTNode {
    type: 'Step';
    keyword: string;
    text: string;
    argument?: StepArgument;
}
