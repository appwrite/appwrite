import type { ASTNode } from './ASTNode';
import type { TableRow } from './TableRow';
import type { Tag } from './Tag';

/**
 * @private
 */
export interface Examples extends ASTNode {
    type: 'Examples';
    tags: Tag[];
    keyword: string;
    name: string;
    description: string;
    tableHeader: TableRow;
    tableBody: TableRow[];
}
