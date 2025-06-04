import { ConfigurationError, TestCompromisedError } from '../errors';
import { ActivityRelatedArtifactGenerated } from '../events';
import { ValueInspector } from '../io';
import type { Artifact } from '../model';
import { Name, } from '../model';
import type { Stage } from '../stage';
import type { AbilityType, CanHaveAbilities, Discardable, Initialisable, UsesAbilities } from './abilities';
import { Ability, AnswerQuestions, PerformActivities } from './abilities';
import type { PerformsActivities } from './activities';
import type { Activity } from './Activity';
import type { Answerable } from './Answerable';
import type { CollectsArtifacts } from './artifacts';
import type { AnswersQuestions } from './questions';
import type { SerialisedActor } from './SerialisedActor';
import type { TellsTime, Timestamp } from './time';

/**
 * **Actors** represent **people** and **external systems** interacting with the system under test.
 * Their role is to perform [activities](https://serenity-js.org/api/core/class/Activity/) that demonstrate how to accomplish a given goal.
 *
 * Actors are the core building block of the [Screenplay Pattern](https://serenity-js.org/handbook/design/screenplay-pattern),
 * along with [abilities](https://serenity-js.org/api/core/class/Ability/), [interactions](https://serenity-js.org/api/core/class/Interaction/), [tasks](https://serenity-js.org/api/core/class/Task/), and [questions](https://serenity-js.org/api/core/class/Question/).
 * Actors are also the first thing you see in a typical Serenity/JS test scenario.
 *
 * ![Screenplay Pattern](https://serenity-js.org/images/design/serenity-js-screenplay-pattern.png)
 *
 * Learn more about:
 * - [`Cast`](https://serenity-js.org/api/core/class/Cast/)
 * - [`Stage`](https://serenity-js.org/api/core/class/Stage/)
 * - [`Ability`](https://serenity-js.org/api/core/class/Ability/)
 * - [`Activity`](https://serenity-js.org/api/core/class/Activity/)
 * - [`Interaction`](https://serenity-js.org/api/core/class/Interaction/)
 * - [`Task`](https://serenity-js.org/api/core/class/Task/)
 * - [`Question`](https://serenity-js.org/api/core/class/Question/)
 *
 * ## Representing people and systems as actors
 *
 * To use a Serenity/JS [`Actor`](https://serenity-js.org/api/core/class/Actor/), all you need is to say their name:
 *
 * ```typescript
 * import { actorCalled } from '@serenity-js/core'
 *
 * actorCalled('Alice')
 * // returns: Actor
 * ```
 *
 * Serenity/JS actors perform within the scope of a test scenario, so the first time you invoke [`actorCalled`](https://serenity-js.org/api/core/function/actorCalled/),
 * Serenity/JS instantiates a new actor from the default [cast](https://serenity-js.org/api/core/class/Cast/) of actors (or any custom cast you might have [configured](https://serenity-js.org/api/core/function/configure/)).
 * Any subsequent invocations of this function within the scope of the same test scenario retrieve the already instantiated actor, identified by their name.
 *
 * ```typescript
 * import { actorCalled } from '@serenity-js/core'
 *
 * actorCalled('Alice')    // instantiates Alice
 * actorCalled('Bob')      // instantiates Bob
 * actorCalled('Alice')    // retrieves Alice, since she's already been instantiated
 * ```
 *
 * Serenity/JS scenarios can involve as many or as few actors as you need to model the given business workflow.
 * For example, you might want to use **multiple actors** in test scenarios that model how **different people** perform different parts of a larger business process, such as reviewing and approving a loan application.
 * It is also quite common to introduce **supporting actors** to perform **administrative tasks**, like setting up test data and environment, or **audit tasks**, like checking the logs or messages emitted to a message queue
 * by the system under test.
 *
 * :::info The Stan Lee naming convention
 * Actor names can be much more than just simple identifiers like `Alice` or `Bob`. While you can give your actors any names you like, a good convention to follow is to give them
 * names indicating the [personae](https://articles.uie.com/goodwin_interview/) they represent or the role they play in the system.
 *
 * Just like the characters in [Stan Lee](https://en.wikipedia.org/wiki/Stan_Lee) graphic novels,
 * actors in Serenity/JS test scenarios are often given alliterate names as a mnemonic device.
 * Names like "Adam the Admin", "Edna the Editor", "Trevor the Traveller", are far more memorable than a generic "UI user" or "API user".
 * They're also much easier for people to associate with the context, constraints, and affordances of the given actor.
 * :::
 *
 * @group Screenplay Pattern
 */
export class Actor implements PerformsActivities,
    UsesAbilities,
    CanHaveAbilities<Actor>,
    AnswersQuestions,
    CollectsArtifacts,
    TellsTime
{
    private readonly abilities: Map<AbilityType<Ability>, Ability> = new Map<AbilityType<Ability>, Ability>();

    constructor(
        public readonly name: string,
        private readonly stage: Stage,
        abilities: Ability[] = [],
    ) {
        [
            new PerformActivities(this, stage),
            new AnswerQuestions(this),
            ...abilities
        ].forEach(ability => this.acquireAbility(ability));
    }

    /**
     * Retrieves actor's [`Ability`](https://serenity-js.org/api/core/class/Ability/) of `abilityType`, or one that extends `abilityType`.
     *
     * Please note that this method performs an [`instanceof`](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Operators/instanceof) check against abilities
     * given to this actor via [`Actor.whoCan`](https://serenity-js.org/api/core/class/Actor/#whoCan).
     *
     * Please also note that [`Actor.whoCan`](https://serenity-js.org/api/core/class/Actor/#whoCan) performs the same check when abilities are assigned to the actor
     * to ensure the actor has at most one instance of a given ability type.
     *
     * @param abilityType
     */
    abilityTo<T extends Ability>(abilityType: AbilityType<T>): T {
        const found = this.findAbilityTo(abilityType);

        if (! found) {
            throw new ConfigurationError(
                `${ this.name } can ${ Array.from(this.abilities.keys()).map(type => type.name).join(', ') }. ` +
                `They can't, however, ${ abilityType.name } yet. ` +
                `Did you give them the ability to do so?`
            );
        }

        return found;
    }

    /**
     * Instructs the actor to attempt to perform a number of [activities](https://serenity-js.org/api/core/class/Activity/),
     * so either [tasks](https://serenity-js.org/api/core/class/Task/) or [interactions](https://serenity-js.org/api/core/class/Interaction/)),
     * one by one.
     *
     * @param {...activities: Activity[]} activities
     */
    attemptsTo(...activities: Activity[]): Promise<void> {
        return activities
            .reduce((previous: Promise<void>, current: Activity) => {
                return previous
                    .then(() => PerformActivities.as(this).perform(current));
            }, this.initialiseAbilities());
    }

    /**
     * Gives this Actor a list of [abilities](https://serenity-js.org/api/core/class/Ability/) they can use
     * to interact with the system under test or the test environment.
     *
     * @param abilities
     *  A vararg list of abilities to give the actor
     *
     * @returns
     *  The actor with newly gained abilities
     *
     * @throws [`ConfigurationError`](https://serenity-js.org/api/core/class/ConfigurationError/)
     *  Throws a ConfigurationError if the actor already has an ability of this type.
     */
    whoCan(...abilities: Ability[]): Actor {
        abilities.forEach(ability => this.acquireAbility(ability));

        return this;
    }

    /**
     * @param answerable -
     *  An [`Answerable`](https://serenity-js.org/api/core/#Answerable) to answer (resolve the value of).
     *
     * @returns
     *  The answer to the Answerable
     */
    answer<T>(answerable: Answerable<T>): Promise<T> {
        return AnswerQuestions.as(this).answer(answerable);
    }

    /**
     * @inheritDoc
     */
    collect(artifact: Artifact, name?: string | Name): void {
        this.stage.announce(new ActivityRelatedArtifactGenerated(
            this.stage.currentSceneId(),
            this.stage.currentActivityId(),
            this.nameFrom(name || new Name(artifact.constructor.name)),
            artifact,
            this.stage.currentTime(),
        ));
    }

    /**
     * Returns current time.
     */
    currentTime(): Timestamp {
        return this.stage.currentTime();
    }

    /**
     * Instructs the actor to invoke [`Discardable.discard`](https://serenity-js.org/api/core/interface/Discardable/#discard) method on any
     * [discardable](https://serenity-js.org/api/core/interface/Discardable/) [ability](https://serenity-js.org/api/core/class/Ability/) it's been configured with.
     */
    dismiss(): Promise<void> {
        return this.findAbilitiesOfType<Discardable>('discard')
            .reduce(
                (previous: Promise<void>, ability: (Discardable & Ability)) =>
                    previous.then(() => ability.discard()),
                Promise.resolve(void 0),
            ) as Promise<void>;
    }

    /**
     * Returns a human-readable, string representation of this actor and their abilities.
     *
     * **PRO TIP:** To get the name of the actor, use [`Actor.name`](https://serenity-js.org/api/core/class/Actor/#name)
     */
    toString(): string {
        const abilities = Array.from(this.abilities.values()).map(ability => ability.constructor.name);

        return `Actor(name=${ this.name }, abilities=[${ abilities.join(', ') }])`;
    }

    /**
     * Returns a JSON representation of the actor and its current state.
     *
     * The purpose of this method is to enable reporting the state of the actor in a human-readable format,
     * rather than to serialise and deserialise the actor itself.
     */
    toJSON(): SerialisedActor {
        return {
            name: this.name,
            abilities: Array.from(this.abilities.values()).map(ability => ability.toJSON())
        }
    }

    private initialiseAbilities(): Promise<void> {
        return this.findAbilitiesOfType<Initialisable>('initialise', 'isInitialised')
            .filter(ability => !ability.isInitialised())
            .reduce(
                (previous: Promise<void>, ability: (Initialisable & Ability)) =>
                    previous
                        .then(() => ability.initialise())
                        .catch(error => {
                            throw new TestCompromisedError(`${ this.name } couldn't initialise the ability to ${ ability.constructor.name }`, error);
                        }),
                Promise.resolve(void 0),
            )
    }

    private findAbilitiesOfType<T>(...methodNames: Array<keyof T>): Array<Ability & T> {
        const abilitiesFrom = (map: Map<AbilityType<Ability>, Ability>): Ability[] =>
            Array.from(map.values());

        const abilitiesWithDesiredMethods = (ability: Ability & T): boolean =>
            methodNames.every(methodName => typeof (ability[methodName]) === 'function');

        return abilitiesFrom(this.abilities)
            .filter(abilitiesWithDesiredMethods) as Array<Ability & T>;
    }

    private findAbilityTo<T extends Ability>(doSomething: AbilityType<T>): T | undefined {
        return this.abilities.get(doSomething.abilityType()) as T;
    }

    private acquireAbility(ability: Ability): void {
        if (!(ability instanceof Ability)) {
            throw new ConfigurationError(`Custom abilities must extend Ability from '@serenity-js/core'. Received ${ ValueInspector.typeOf(ability) }`);
        }

        this.abilities.set(ability.abilityType(), ability);
    }

    /**
     * Instantiates a `Name` based on the string value of the parameter,
     * or returns the argument if it's already an instance of `Name`.
     *
     * @param maybeName
     */
    private nameFrom(maybeName: string | Name): Name {
        return typeof maybeName === 'string'
            ? new Name(maybeName)
            : maybeName;
    }
}
