import type { ASTNode } from './ASTNode';

/**
 * @private
 */
export interface TableCell extends ASTNode {
    value: string;
}
