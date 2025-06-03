/**
 * An interface to be implemented by any [`Ability`](https://serenity-js.org/api/core/class/Ability/) that needs to initialise
 * the resources it uses, e.g. establish a database connection.
 *
 * The [`Initialisable.initialise`](https://serenity-js.org/api/core/interface/Initialisable/#initialise) method is invoked whenever [`Actor.attemptsTo`](https://serenity-js.org/api/core/class/Actor/#attemptsTo) method is called,
 * but **only when** [`Initialisable.isInitialised`](https://serenity-js.org/api/core/interface/Initialisable/#isInitialised) returns false. This is to avoid initialising abilities more than once.
 *
 * ## Learn more
 * - [`Ability`](https://serenity-js.org/api/core/class/Ability/)
 * - [`AbilityType`](https://serenity-js.org/api/core/#AbilityType)
 * - [`Discardable`](https://serenity-js.org/api/core/interface/Discardable/)
 *
 * @group Abilities
 */
export interface Initialisable {

    /**
     * Initialises the ability. Invoked whenever [`Actor.attemptsTo`](https://serenity-js.org/api/core/class/Actor/#attemptsTo) method is called,
     * but **only when** [`Initialisable.isInitialised`](https://serenity-js.org/api/core/interface/Initialisable/#isInitialised) returns false.
     *
     * Make sure to implement [`Initialisable.isInitialised`](https://serenity-js.org/api/core/interface/Initialisable/#isInitialised) so that it returns `true`
     * when the ability has been successfully initialised.
     */
    initialise(): Promise<void> | void;

    /**
     * Should return `true` when all the resources that the given ability needs
     * have been initialised. Should return `false` if the [`Actor`](https://serenity-js.org/api/core/class/Actor/) should
     * initialise them again when [`Actor.attemptsTo`](https://serenity-js.org/api/core/class/Actor/#attemptsTo) is called.
     *
     * @returns {boolean}
     */
    isInitialised(): boolean;
}
