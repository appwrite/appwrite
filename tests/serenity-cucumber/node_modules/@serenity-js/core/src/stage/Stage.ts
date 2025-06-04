import { ensure, isDefined } from 'tiny-types';

import { ConfigurationError, type ErrorFactory, type ErrorOptions, LogicError, RaiseErrors,type RuntimeError } from '../errors';
import {
    ActorEntersStage,
    ActorStageExitAttempted,
    ActorStageExitCompleted,
    ActorStageExitFailed,
    ActorStageExitStarts,
    type DomainEvent,
    type EmitsDomainEvents,
    SceneFinishes,
    SceneStarts,
    TestRunFinishes
} from '../events';
import { type ActivityDetails, CorrelationId, Name } from '../model';
import { Actor, type Clock, type Duration, ScheduleWork, type Timestamp } from '../screenplay';
import type { ListensToDomainEvents } from '../stage';
import type { Cast } from './Cast';
import type { StageManager } from './StageManager';

/**
 * Stage is the place where [actors](https://serenity-js.org/api/core/class/Actor/) perform.
 *
 * In more technical terms, the Stage is the main event bus propagating [Serenity/JS domain events](https://serenity-js.org/api/core-events/class/DomainEvent/)
 * to [actors](https://serenity-js.org/api/core/class/Actor/) it instantiates and [stage crew members](https://serenity-js.org/api/core/interface/StageCrewMember/) that have been registered with it.
 *
 * It is unlikely that you'll ever need to interact with the `Stage` directly in your tests. Instead, you'll use functions like
 * [`actorCalled`](https://serenity-js.org/api/core/function/actorCalled/) and [`actorInTheSpotlight`](https://serenity-js.org/api/core/function/actorInTheSpotlight/).
 *
 * ## Learn more
 * - [`configure`](https://serenity-js.org/api/core/function/configure/)
 * - [`engage`](https://serenity-js.org/api/core/function/engage/)
 * - [`StageCrewMember`](https://serenity-js.org/api/core/interface/StageCrewMember/)
 *
 * @group Stage
 */
export class Stage implements EmitsDomainEvents {

    public static readonly unknownSceneId = new CorrelationId('unknown')

    /**
     * Actors instantiated after the scene has started,
     * who will be dismissed when the scene finishes.
     */
    private actorsOnFrontStage: Map<string, Actor> = new Map<string, Actor>();

    /**
     * Actors instantiated before the scene has started,
     * who will be dismissed when the test run finishes.
     */
    private actorsOnBackstage: Map<string, Actor> = new Map<string, Actor>();

    private actorsOnStage: Map<string, Actor> = this.actorsOnBackstage;

    /**
     * The most recent actor referenced via the [`Actor`](https://serenity-js.org/api/core/class/Actor/) method
     */
    private actorInTheSpotlight: Actor = undefined;

    private currentActivity: { id: CorrelationId, details: ActivityDetails } = undefined;

    private currentScene: CorrelationId = Stage.unknownSceneId;

    /**
     * @param cast
     * @param manager
     * @param errors
     * @param clock
     * @param interactionTimeout
     */
    constructor(
        private cast: Cast,
        private readonly manager: StageManager,
        private errors: ErrorFactory,
        private readonly clock: Clock,
        private readonly interactionTimeout: Duration,
    ) {
        ensure('Cast', cast, isDefined());
        ensure('StageManager', manager, isDefined());
        ensure('ErrorFactory', errors, isDefined());
        ensure('Clock', clock, isDefined());
        ensure('interactionTimeout', interactionTimeout, isDefined());
    }

    /**
     * An alias for [`Stage.actor`](https://serenity-js.org/api/core/class/Stage/#actor)
     *
     * @param name
     */
    theActorCalled(name: string): Actor {
        return this.actor(name);
    }

    /**
     * Instantiates a new [`Actor`](https://serenity-js.org/api/core/class/Actor/) or fetches an existing one
     * identified by their name if they've already been instantiated.
     *
     * @param name
     *  Case-sensitive name of the Actor, e.g. `Alice`
     */
    actor(name: string): Actor {
        if (! this.instantiatedActorCalled(name)) {
            let actor;
            try {
                const newActor = new Actor(name, this, [
                    new RaiseErrors(this),
                    new ScheduleWork(this.clock, this.interactionTimeout)
                ]);

                actor = this.cast.prepare(newActor);
            }
            catch (error) {
                throw new ConfigurationError(`${ this.typeOf(this.cast) } encountered a problem when preparing actor "${ name }" for stage`, error);
            }

            if (! (actor instanceof Actor)) {
                throw new ConfigurationError(`Instead of a new instance of actor "${ name }", ${ this.typeOf(this.cast) } returned ${ actor }`);
            }

            this.actorsOnStage.set(name, actor);

            this.announce(
                new ActorEntersStage(
                    this.currentScene,
                    actor.toJSON(),
                )
            )
        }

        if (this.actorsOnBackstage.has(name)) {
            this.announce(
                new ActorEntersStage(
                    this.currentScene,
                    this.actorsOnBackstage.get(name).toJSON(),
                )
            )
        }

        this.actorInTheSpotlight = this.instantiatedActorCalled(name);

        return this.actorInTheSpotlight;
    }

    /**
     * Returns the last [`Actor`](https://serenity-js.org/api/core/class/Actor/) instantiated via [`Stage.actor`](https://serenity-js.org/api/core/class/Stage/#actor).
     * Useful when you don't can't or choose not to reference the actor by their name.
     *
     * @throws [`LogicError`](https://serenity-js.org/api/core/class/LogicError/)
     *  If no [`Actor`](https://serenity-js.org/api/core/class/Actor/) has been activated yet
     */
    theActorInTheSpotlight(): Actor {
        if (! this.actorInTheSpotlight) {
            throw new LogicError(`There is no actor in the spotlight yet. Make sure you instantiate one with stage.actor(actorName) before calling this method.`);
        }

        return this.actorInTheSpotlight;
    }

    /**
     * Returns `true` if there is an [`Actor`](https://serenity-js.org/api/core/class/Actor/) in the spotlight, `false` otherwise.
     */
    theShowHasStarted(): boolean {
        return !! this.actorInTheSpotlight;
    }

    /**
     * Configures the Stage to prepare [actors](https://serenity-js.org/api/core/class/Actor/)
     * instantiated via [`Stage.actor`](https://serenity-js.org/api/core/class/Stage/#actor) using the provided [cast](https://serenity-js.org/api/core/class/Cast/).
     *
     * @param actors
     */
    engage(actors: Cast): void {
        ensure('Cast', actors, isDefined());

        this.cast = actors;
    }

    /**
     * Assigns listeners to be notified of [Serenity/JS domain events](https://serenity-js.org/api/core-events/class/DomainEvent/)
     * emitted via [`Stage.announce`](https://serenity-js.org/api/core/class/Stage/#announce).s
     *
     * @param listeners
     */
    assign(...listeners: ListensToDomainEvents[]): void {
        this.manager.register(...listeners);
    }

    /**
     * Notifies all the assigned listeners of the events,
     * emitting them one by one.
     *
     * @param events
     */
    announce(...events: Array<DomainEvent>): void {
        events.forEach(event => {
            this.announceSingle(event)
        });
    }

    private announceSingle(event: DomainEvent): void {
        if (event instanceof SceneStarts) {
            this.actorsOnStage = this.actorsOnFrontStage;
        }

        if (event instanceof SceneFinishes || event instanceof TestRunFinishes) {
            this.notifyOfStageExit(this.currentSceneId());
        }

        this.manager.notifyOf(event);

        if (event instanceof SceneFinishes) {
            this.dismiss(this.actorsOnStage);

            this.actorsOnStage = this.actorsOnBackstage;
        }

        if (event instanceof TestRunFinishes) {
            this.dismiss(this.actorsOnStage);
        }
    }

    /**
     * Returns current time. This method should be used whenever
     * [`DomainEvent`](https://serenity-js.org/api/core-events/class/DomainEvent/) objects are instantiated by you programmatically.
     */
    currentTime(): Timestamp {
        return this.manager.currentTime();
    }

    /**
     * Generates and remembers a `CorrelationId`
     * for the current scene.
     *
     * This method should be used in custom test runner adapters
     * when instantiating a [SceneStarts](https://serenity-js.org/api/core-events/class/SceneStarts/) event.
     *
     * #### Learn more
     * - [`Stage.currentSceneId`](https://serenity-js.org/api/core/class/Stage/#currentSceneId)
     */
    assignNewSceneId(): CorrelationId {
        // todo: inject an id factory to make it easier to test
        this.currentScene = CorrelationId.create();

        return this.currentScene;
    }

    /**
     * Returns the `CorrelationId` for the current scene.
     *
     * #### Learn more
     * - [`Stage.assignNewSceneId`](https://serenity-js.org/api/core/class/Stage/#assignNewSceneId)
     */
    currentSceneId(): CorrelationId {
        return this.currentScene;
    }

    /**
     * Generates and remembers a `CorrelationId`
     * for the current [`Activity`](https://serenity-js.org/api/core/class/Activity/).
     *
     * This method should be used in custom test runner adapters
     * when instantiating the [ActivityStarts](https://serenity-js.org/api/core-events/class/ActivityStarts/) event.
     *
     * #### Learn more
     * - [`Stage.currentActivityId`](https://serenity-js.org/api/core/class/Stage/#currentActivityId)
     */
    assignNewActivityId(activityDetails: ActivityDetails): CorrelationId {
        this.currentActivity = {
            id: CorrelationId.create(),
            details: activityDetails,
        };

        return this.currentActivity.id;
    }

    /**
     * Returns the `CorrelationId` for the current [`Activity`](https://serenity-js.org/api/core/class/Activity/).
     *
     * #### Learn more
     * - [`Stage.assignNewSceneId`](https://serenity-js.org/api/core/class/Stage/#assignNewSceneId)
     */
    currentActivityId(): CorrelationId {
        if (! this.currentActivity) {
            throw new LogicError(`No activity is being performed. Did you call assignNewActivityId before invoking currentActivityId?`);
        }

        return this.currentActivity.id;
    }

    /**
     * Returns a Promise that will be resolved when any asynchronous
     * post-processing activities performed by Serenity/JS are completed.
     *
     * Invoked in Serenity/JS test runner adapters to inform the test runner when
     * the scenario has finished and when it's safe for the test runner to proceed
     * with the next test, or finish execution.
     */
    waitForNextCue(): Promise<void> {
        return this.manager.waitForNextCue();
    }

    createError<RE extends RuntimeError>(errorType: new (...args: any[]) => RE, options: ErrorOptions): RE {
        return this.errors.create(errorType, {
            location: this.currentActivity?.details.location,
            ...options,
        });
    }

    private instantiatedActorCalled(name: string): Actor | undefined {
        return this.actorsOnBackstage.has(name)
            ? this.actorsOnBackstage.get(name)
            : this.actorsOnFrontStage.get(name)
    }

    private notifyOfStageExit(sceneId: CorrelationId): void {
        for (const actor of this.actorsOnStage.values()) {
            this.announce(new ActorStageExitStarts(
                sceneId,
                actor.toJSON(),
                this.currentTime(),
            ));
        }
    }

    private async dismiss(activeActors: Map<string, Actor>): Promise<void> {
        const actors = Array.from(activeActors.values());

        if (actors.includes(this.actorInTheSpotlight)) {
            this.actorInTheSpotlight = undefined;
        }

        // Wait for the Photographer to finish taking any screenshots
        await this.manager.waitForAsyncOperationsToComplete();

        const actorsToDismiss = new Map<Actor, CorrelationId>(actors.map(actor => [actor, CorrelationId.create()]));

        for (const [ actor, correlationId ] of actorsToDismiss) {
            this.announce(new ActorStageExitAttempted(
                correlationId,
                new Name(actor.name),
                this.currentTime(),
            ));
        }

        // Try to dismiss each actor
        for (const [ actor, correlationId ] of actorsToDismiss) {
            try {
                await actor.dismiss();

                this.announce(new ActorStageExitCompleted(correlationId, new Name(actor.name), this.currentTime()));
            }
            catch (error) {
                this.announce(new ActorStageExitFailed(
                    error,
                    correlationId,
                    this.currentTime()
                ));
            }
        }

        activeActors.clear();
    }

    private typeOf(cast: Cast): string {
        return cast.constructor === Object
            ? 'Cast'
            : cast.constructor.name;
    }
}
