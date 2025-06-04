import type { FileSystemLocation } from '@serenity-js/core/lib/io';
import type { Description, Name, ScenarioParameters } from '@serenity-js/core/lib/model';

import { FeatureFileNode } from './FeatureFileNode';
import type { Step } from './Step';

/**
 * @private
 */
export class ScenarioOutline extends FeatureFileNode {
    constructor(
        location: FileSystemLocation,
        name: Name,
        public readonly description: Description,
        public readonly steps: Step[],
        public readonly parameters: { [line: number]: ScenarioParameters },
    ) {
        super(location, name);
    }
}
