import type { Ability } from './Ability';
import type { UsesAbilities } from './UsesAbilities';

/**
 * An interface describing the static access method that every [`Ability`](https://serenity-js.org/api/core/class/Ability/) class
 * needs to provide in order to be accessible from within the [interactions](https://serenity-js.org/api/core/class/Interaction/).
 *
 * #### Retrieving an ability from an interaction
 *
 * ```ts
 * import { Ability, Answerable, actorCalled, Interaction, the } from '@serenity-js/core';
 *
 * class MakePhoneCalls extends Ability {
 *   static using(phone: Phone) {
 *     return new MakePhoneCalls(phone);
 *   }
 *
 *   protected constructor(private readonly phone: Phone) {
 *   }
 *
 *   // some method that allows us to interact with the external interface of the system under test
 *   dial(phoneNumber: string): Promise<void> {
 *     // ...
 *   }
 * }
 *
 * const Call = (phoneNumber: Answerable<string>) =>
 *   Interaction.where(the`#actor calls ${ phoneNumber }`, async actor => {
 *     await MakePhoneCalls.as(actor).dial(phoneNumber)
 *   });
 *
 * await actorCalled('Connie')
 *   .whoCan(MakePhoneCalls.using(phone))
 *   .attemptsTo(
 *     Call(phoneNumber),
 *   )
 * ```
 *
 * ## Learn more
 * - [`Ability`](https://serenity-js.org/api/core/class/Ability/)
 * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
 * - [`Interaction`](https://serenity-js.org/api/core/class/Interaction/)
 *
 * @group Abilities
 */
export type AbilityType<A extends Ability> =
    (abstract new (... args: any[]) => A) & {
        as<S extends Ability>(
            this: AbilityType<S>,
            actor: UsesAbilities
        ): S;
        abilityType(): AbilityType<Ability>
    };
