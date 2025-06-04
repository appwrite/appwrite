import type { JSONObject } from 'tiny-types';

import { Tag } from './Tag';

/**
 * @access public
 */
export class BrowserTag extends Tag {
    static readonly Type = 'browser';

    static fromJSON(o: JSONObject): BrowserTag {
        return new BrowserTag(o.browserName as string, o.browserVersion as string);
    }

    constructor(
        public readonly browserName: string,
        public readonly browserVersion: string = '',
    ) {
        super(
            [ browserName, browserVersion ]
                .filter(_ => !! _)
                .join(' '),
            BrowserTag.Type,
        );
    }

    toJSON(): { name: string, type: string, browserName: string, browserVersion: string } {
        return {
            name: this.name,
            type: BrowserTag.Type,
            browserName: this.browserName,
            browserVersion: this.browserVersion,
        };
    }
}
