import type { FileSystemLocation } from '@serenity-js/core/lib/io';
import type { Description, Name, Tag } from '@serenity-js/core/lib/model';

import { FeatureFileNode } from './FeatureFileNode';
import type { Hook } from './Hook';
import type { Step } from './Step';

/**
 * @private
 */
export class Scenario extends FeatureFileNode {
    constructor(
        location: FileSystemLocation,
        name: Name,
        public readonly description: Description,
        public readonly steps: Array<Step | Hook>,
        public readonly tags: Tag[] = [],
        public readonly outline?: FileSystemLocation,
    ) {
        super(location, name);
    }
}
