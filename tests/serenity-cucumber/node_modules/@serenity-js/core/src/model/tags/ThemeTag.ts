import { Tag } from './Tag';

/**
 * @access public
 */
export class ThemeTag extends Tag {
    static readonly Type = 'theme';

    constructor(theme: string) {
        super(theme, ThemeTag.Type);
    }
}
