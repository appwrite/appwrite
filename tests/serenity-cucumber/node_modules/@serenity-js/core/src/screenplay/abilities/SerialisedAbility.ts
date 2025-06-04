import type { JSONValue } from 'tiny-types';

/**
 * @group Abilities
 */
export interface SerialisedAbility {
    type: string;
    class?: string;
    options?: JSONValue;
}
