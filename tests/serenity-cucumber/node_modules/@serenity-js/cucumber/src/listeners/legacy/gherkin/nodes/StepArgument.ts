import type { ASTNode } from './ASTNode';

/**
 * @private
 */
export interface StepArgument extends ASTNode {
    type: 'DataTable' | 'DocString';
}
