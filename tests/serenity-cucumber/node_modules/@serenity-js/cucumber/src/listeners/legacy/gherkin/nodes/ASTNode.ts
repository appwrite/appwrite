import type { Location } from './Location';

/**
 * @private
 */
export interface ASTNode {
    type: string;
    location: Location;
}
