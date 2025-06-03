import type { Examples } from './Examples';
import type { ScenarioDefinition } from './ScenarioDefinition';
import type { Tag } from './Tag';

/**
 * @private
 */
export interface ScenarioOutline extends ScenarioDefinition {
    type: 'ScenarioOutline';
    tags: Tag[];
    examples: Examples[];
}
