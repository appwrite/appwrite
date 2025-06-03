import type { ASTNode } from './ASTNode';

/**
 * @private
 */
export interface Tag extends ASTNode {
    type: 'Tag';
    name: string;
}
