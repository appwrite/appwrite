import { Tag } from './Tag';

/**
 * @access public
 */
export class CapabilityTag extends Tag {
    static readonly Type = 'capability';

    constructor(capability: string) {
        super(capability, CapabilityTag.Type);
    }
}
