import type { Ability } from './Ability';
import type { AbilityType } from './AbilityType';

/**
 * Describes an [`Actor`](https://serenity-js.org/api/core/class/Actor/) who can use their [abilities](https://serenity-js.org/api/core/class/Ability/) to perform an [`Activity`](https://serenity-js.org/api/core/class/Activity/)
 * or answer a [`Question`](https://serenity-js.org/api/core/class/Question/).
 *
 * ## Learn more
 *
 * - [`Ability`](https://serenity-js.org/api/core/class/Ability/)
 * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
 *
 * @group Actors
 */
export interface UsesAbilities {

    /**
     * Provides access to the [actor's](https://serenity-js.org/api/core/class/Actor/) [`Ability`](https://serenity-js.org/api/core/class/Ability/) to do something
     *
     * @param doSomething
     *  The type of ability to look up, e.g. [`BrowseTheWeb`](https://serenity-js.org/api/web/class/BrowseTheWeb/) or [`CallAnApi`](https://serenity-js.org/api/rest/class/CallAnApi/)
     */
    abilityTo<T extends Ability>(doSomething: AbilityType<T>): T;
}
