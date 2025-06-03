import { Tag } from './Tag';

/**
 * Tagged with an arbitrary tag that doesn't have any special interpretation
 * such as `@regression`, `@wip`, etc.
 */
export class ArbitraryTag extends Tag {
    static readonly Type = 'tag';

    constructor(value: string) {
        super(value, ArbitraryTag.Type);
    }
}
