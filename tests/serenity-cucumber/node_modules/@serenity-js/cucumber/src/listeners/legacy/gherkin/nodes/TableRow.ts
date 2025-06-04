import type { ASTNode } from './ASTNode';
import type { TableCell } from './TableCell';

/**
 * @private
 */
export interface TableRow extends ASTNode {
    type: 'TableRow';
    cells: TableCell[];
}
