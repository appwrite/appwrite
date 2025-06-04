import type { Ability } from './Ability';
import type { UsesAbilities } from './UsesAbilities';

/**
 * Describes an [`Actor`](https://serenity-js.org/api/core/class/Actor/) who can have an [`Ability`](https://serenity-js.org/api/core/class/Ability/) to perform some [`Activity`](https://serenity-js.org/api/core/class/Activity/).
 *
 * ## Learn more
 *
 * - [`Ability`](https://serenity-js.org/api/core/class/Ability/)
 * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
 *
 * @group Actors
 */
export interface CanHaveAbilities<Returned_Type = UsesAbilities> {

    /**
     * Assigns an [`Ability`](https://serenity-js.org/api/core/class/Ability/) or several abilities to the [`Actor`](https://serenity-js.org/api/core/class/Actor/)
     */
    whoCan(...abilities: Ability[]): Returned_Type;
}
