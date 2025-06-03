import type { SerialisedAbility } from './abilities';

/**
 * @group Actors
 */
export interface SerialisedActor {
    name: string;
    abilities: Array<SerialisedAbility>;
}
