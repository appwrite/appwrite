import type { FileSystemLocation } from '@serenity-js/core/lib/io';
import type { Description, Name } from '@serenity-js/core/lib/model';

import type { Background } from './Background';
import { FeatureFileNode } from './FeatureFileNode';

/**
 * @private
 */
export class Feature extends FeatureFileNode {
    constructor(
        location: FileSystemLocation,
        name: Name,
        public readonly description: Description,
        public readonly background?: Background,
    ) {
        super(location, name);
    }
}
