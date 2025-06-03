import { Tag } from './Tag';

/**
 * @access public
 */
export class ManualTag extends Tag {
    static readonly Type = 'External Tests';

    constructor(name = 'Manual') {  // parametrised constructor to make all tag constructors compatible
        super(name, ManualTag.Type);
    }
}
