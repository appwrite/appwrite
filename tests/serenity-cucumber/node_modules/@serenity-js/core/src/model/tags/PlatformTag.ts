import type { JSONObject } from 'tiny-types';

import { Tag } from './Tag';

/**
 * @access public
 */
export class PlatformTag extends Tag {
    static readonly Type = 'platform';

    static fromJSON(o: JSONObject): PlatformTag {
        return new PlatformTag(o.platformName as string, o.platformVersion as string);
    }

    constructor(
        public readonly platformName: string,
        public readonly platformVersion: string = '',
    ) {
        super(
            [ platformName, platformVersion ]
                .filter(_ => !! _)
                .join(' '),
            PlatformTag.Type,
        );
    }

    toJSON(): { name: string, type: string, platformName: string, platformVersion: string } {
        return {
            name: this.name,
            type: PlatformTag.Type,
            platformName: this.platformName,
            platformVersion: this.platformVersion,
        };
    }
}
