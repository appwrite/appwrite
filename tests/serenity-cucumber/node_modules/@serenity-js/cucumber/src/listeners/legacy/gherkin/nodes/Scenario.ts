import type { ScenarioDefinition } from './ScenarioDefinition';
import type { Tag } from './Tag';

/**
 * @private
 */
export interface Scenario extends ScenarioDefinition {
    type: 'Scenario';
    tags: Tag[];
}
