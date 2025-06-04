import type { ASTNode } from './ASTNode';

/**
 * @private
 */
export interface Comment extends ASTNode {
    type: 'Comment';
    text: string;
}
