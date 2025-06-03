/**
 * An interface to be implemented by any [`Ability`](https://serenity-js.org/api/core/class/Ability/) that needs to free up
 * the resources it uses, e.g. disconnect from a database.
 *
 * This [`Discardable.discard`](https://serenity-js.org/api/core/interface/Discardable/#discard) method is invoked directly by the [actor](https://serenity-js.org/api/core/class/Actor/), and indirectly by the [stage](https://serenity-js.org/api/core/class/Stage/):
 * - when [SceneFinishes](https://serenity-js.org/api/core-events/class/SceneFinishes/), for actors instantiated after [SceneStarts](https://serenity-js.org/api/core-events/class/SceneStarts/) - e.g. within a test scenario or in a "before each" hook
 * - when [`TestRunFinishes`](https://serenity-js.org/api/core-events/class/TestRunFinishes/), for actors instantiated before [SceneStarts](https://serenity-js.org/api/core-events/class/SceneStarts/) - e.g. in a "before all" hook
 *
 * Note that events such as [SceneFinishes](https://serenity-js.org/api/core-events/class/SceneFinishes/) and [`TestRunFinishes`](https://serenity-js.org/api/core-events/class/TestRunFinishes/) are emitted by Serenity/JS test runner adapters,
 * such as `@serenity-js/cucumber`, `@serenity-js/mocha`, `@serenity-js/jasmine`, and so on.
 * Consult their respective readmes to learn how to register them with your test runner of choice.
 *
 * ## Learn more
 * - [`Ability`](https://serenity-js.org/api/core/class/Ability/)
 * - [`AbilityType`](https://serenity-js.org/api/core/#AbilityType)
 * - [`Initialisable`](https://serenity-js.org/api/core/interface/Initialisable/)
 *
 * @group Abilities
 */
export interface Discardable {

    /**
     * Discards the resources associated with this ability.
     */
    discard(): Promise<void> | void;
}
