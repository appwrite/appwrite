import { ensure, isDefined, isInstanceOf, property } from 'tiny-types';

import type { OutputStream } from './adapter';
import type { SerenityConfig } from './config';
import type { ErrorOptions, RuntimeError } from './errors';
import { ConfigurationError, ErrorFactory, NoOpDiffFormatter } from './errors';
import type { DomainEvent, EmitsDomainEvents } from './events';
import { ClassDescriptionParser, ClassLoader, d, FileSystem, has, ModuleLoader, Path } from './io';
import type { ActivityDetails, CorrelationId } from './model';
import type { Actor, Timestamp } from './screenplay';
import { Clock, Duration } from './screenplay';
import type { StageCrewMember, StageCrewMemberBuilder } from './stage';
import type { Cast } from './stage/Cast';
import { Extras } from './stage/Extras';
import { Stage } from './stage/Stage';
import { StageManager } from './stage/StageManager';

/**
 * @group Serenity
 */
export class Serenity implements EmitsDomainEvents {
    private static defaultCueTimeout            = Duration.ofSeconds(5);
    private static defaultInteractionTimeout    = Duration.ofSeconds(5);
    private static defaultActors                = new Extras();

    private stage: Stage;
    private readonly fileSystem: FileSystem;
    private outputStream: OutputStream  = process.stdout;

    private readonly classLoader: ClassLoader;
    private readonly workingDirectory: Path;

    /**
     * @param clock
     * @param cwd
     */
    constructor(
        private readonly clock: Clock = new Clock(),
        cwd: string = process.cwd(),
    ) {
        this.stage = new Stage(
            Serenity.defaultActors,
            new StageManager(Serenity.defaultCueTimeout, clock),
            new ErrorFactory(),
            clock,
            Serenity.defaultInteractionTimeout,
        );

        this.classLoader = new ClassLoader(
            new ModuleLoader(cwd),
            new ClassDescriptionParser(),
        );

        this.workingDirectory = new Path(cwd);

        this.fileSystem = new FileSystem(this.workingDirectory);
    }

    /**
     * Configures Serenity/JS. Every call to this function
     * replaces the previous configuration provided,
     * so this function should be called exactly once
     * in your test suite.
     *
     * @param config
     */
    configure(config: SerenityConfig): void {
        const looksLikeBuilder          = has<StageCrewMemberBuilder>({ build: 'function' });
        const looksLikeStageCrewMember  = has<StageCrewMember>({ assignedTo: 'function', notifyOf: 'function' });

        const cueTimeout = config.cueTimeout
            ? ensure('cueTimeout', config.cueTimeout, isInstanceOf(Duration))
            : Serenity.defaultCueTimeout;

        const interactionTimeout = config.interactionTimeout
            ? ensure('interactionTimeout', config.interactionTimeout, isInstanceOf(Duration))
            : Serenity.defaultInteractionTimeout;

        if (config.outputStream) {
            this.outputStream = config.outputStream;
        }

        this.stage = new Stage(
            Serenity.defaultActors,
            new StageManager(cueTimeout, this.clock),
            new ErrorFactory(config.diffFormatter ?? new NoOpDiffFormatter()),
            this.clock,
            interactionTimeout,
        );

        if (config.actors) {
            this.engage(config.actors);
        }

        if (Array.isArray(config.crew)) {
            this.stage.assign(
                ...config.crew.map((stageCrewMemberDescription, i) => {

                    const stageCrewMember = this.classLoader.looksLoadable(stageCrewMemberDescription)
                        ? this.classLoader.instantiate<StageCrewMember | StageCrewMemberBuilder>(stageCrewMemberDescription)
                        : stageCrewMemberDescription;

                    if (looksLikeBuilder(stageCrewMember)) {
                        return stageCrewMember.build({
                            stage: this.stage,
                            fileSystem: this.fileSystem,
                            outputStream: this.outputStream,
                        });
                    }

                    if (looksLikeStageCrewMember(stageCrewMember)) {
                        return stageCrewMember.assignedTo(this.stage);
                    }

                    throw new ConfigurationError(
                        d`Entries under \`crew\` should implement either StageCrewMember or StageCrewMemberBuilder interfaces, \`${ stageCrewMemberDescription }\` found at index ${ i }`
                    );
                }),
            );
        }
    }

    /**
     * Re-configures Serenity/JS with a new [cast](https://serenity-js.org/api/core/class/Cast/) of [actors](https://serenity-js.org/api/core/class/Actor/)
     * you want to use in any subsequent calls to [`actorCalled`](https://serenity-js.org/api/core/function/actorCalled/).
     *
     * For your convenience, use [`engage`](https://serenity-js.org/api/core/function/engage/) function instead,
     * which provides an alternative to calling [`Actor.whoCan`](https://serenity-js.org/api/core/class/Actor/#whoCan) directly in your tests
     * and is typically invoked in a "before all" or "before each" hook of your test runner of choice.
     *
     * If your implementation of the [cast](https://serenity-js.org/api/core/class/Cast/) interface is stateless,
     * you can invoke this function just once before your entire test suite is executed, see
     * - [`beforeAll`](https://jasmine.github.io/api/3.6/global.html#beforeAll) in Jasmine,
     * - [`before`](https://mochajs.org/#hooks) in Mocha,
     * - [`BeforeAll`](https://github.com/cucumber/cucumber-js/blob/master/docs/support_files/hooks.md#beforeall--afterall) in Cucumber.js
     *
     * However, if your [cast](https://serenity-js.org/api/core/class/Cast/) holds state that you want to reset before each scenario,
     * it's better to invoke `engage` before each test using:
     * - [`beforeEach`](https://jasmine.github.io/api/3.6/global.html#beforeEach) in Jasmine
     * - [`beforeEach`](https://mochajs.org/#hooks) in Mocha,
     * - [`Before`](https://github.com/cucumber/cucumber-js/blob/master/docs/support_files/hooks.md#hooks) in Cucumber.js
     *
     * ## Engaging a cast of actors
     *
     * ```ts
     * import { Actor, Cast } from '@serenity-js/core';
     *
     * class Actors implements Cast {
     *   prepare(actor: Actor) {
     *     return actor.whoCan(
     *       // ... abilities you'd like the Actor to have
     *     );
     *   }
     * }
     *
     * engage(new Actors());
     * ```
     *
     * ### Using with Mocha test runner
     *
     * ```ts
     * import { beforeEach } from 'mocha'
     *
     * beforeEach(() => engage(new Actors()))
     * ```
     *
     * ### Using with Jasmine test runner
     *
     * ```ts
     * import 'jasmine'
     *
     * beforeEach(() => engage(new Actors()))
     * ```
     *
     * ### Using with Cucumber.js test runner
     *
     * ```ts
     * import { Before } from '@cucumber/cucumber'
     *
     * Before(() => engage(new Actors()))
     * ```
     *
     * ## Learn more
     * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
     * - [`Cast`](https://serenity-js.org/api/core/class/Cast/)
     * - [`engage`](https://serenity-js.org/api/core/function/engage/)
     *
     * @param actors
     */
    engage(actors: Cast): void {
        this.stage.engage(
            ensure('actors', actors, property('prepare', isDefined())),
        );
    }

    /**
     * Instantiates or retrieves an [`Actor`](https://serenity-js.org/api/core/class/Actor/)
     * called `name` if one has already been instantiated.
     *
     * For your convenience, use [`actorCalled`](https://serenity-js.org/api/core/function/actorCalled/) function instead.
     *
     * ## Usage with Mocha
     *
     * ```typescript
     *   import { describe, it } from 'mocha';
     *   import { actorCalled } from '@serenity-js/core';
     *
     *   describe('Feature', () => {
     *
     *      it('should have some behaviour', () =>
     *          actorCalled('James').attemptsTo(
     *              // ... activities
     *          ))
     *   })
     * ```
     *
     * ## Usage with Jasmine
     *
     * ```typescript
     *   import 'jasmine';
     *   import { actorCalled } from '@serenity-js/core';
     *
     *   describe('Feature', () => {
     *
     *      it('should have some behaviour', () =>
     *          actorCalled('James').attemptsTo(
     *              // ... activities
     *          ))
     *   })
     * ```
     *
     * ## Usage with Cucumber
     *
     * ```typescript
     * import { actorCalled } from '@serenity-js/core';
     * import { Given } from '@cucumber/cucumber';
     *
     * Given(/(.*?) is a registered user/, (name: string) =>
     *   actorCalled(name).attemptsTo(
     *     // ... activities
     *   ))
     * ```
     *
     * ## Learn more
     *
     * - [`engage`](https://serenity-js.org/api/core/function/engage/)
     * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
     * - [`Cast`](https://serenity-js.org/api/core/class/Cast/)
     * - [`actorCalled`](https://serenity-js.org/api/core/function/actorCalled/)
     *
     * @param name
     *  The name of the actor to instantiate or retrieve
     */
    theActorCalled(name: string): Actor {
        return this.stage.theActorCalled(name);
    }

    /**
     * Retrieves an actor who was last instantiated or retrieved
     * using [`Serenity.theActorCalled`](https://serenity-js.org/api/core/class/Serenity/#theActorCalled).
     *
     * This function is particularly useful when automating Cucumber scenarios.
     *
     * For your convenience, use [`actorInTheSpotlight`](https://serenity-js.org/api/core/function/actorInTheSpotlight/) function instead.
     *
     * ## Usage with Cucumber
     *
     * ```ts
     * import { actorCalled } from '@serenity-js/core';
     * import { Given, When } from '@cucumber/cucumber';
     *
     * Given(/(.*?) is a registered user/, (name: string) =>
     *   actorCalled(name).attemptsTo(
     *     // ... activities
     *   ))
     *
     * When(/(?:he|she|they) browse their recent orders/, () =>
     *   actorInTheSpotlight().attemptsTo(
     *     // ... activities
     *   ))
     * ```
     *
     * ## Learn more
     *
     * - [`engage`](https://serenity-js.org/api/core/function/engage/)
     * - [`actorCalled`](https://serenity-js.org/api/core/function/actorCalled/)
     * - [`actorInTheSpotlight`](https://serenity-js.org/api/core/function/actorInTheSpotlight/)
     * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
     * - [`Cast`](https://serenity-js.org/api/core/class/Cast/)
     */
    theActorInTheSpotlight(): Actor {
        return this.stage.theActorInTheSpotlight();
    }

    announce(...events: Array<DomainEvent>): void {
        this.stage.announce(...events);
    }

    currentTime(): Timestamp {
        return this.stage.currentTime();
    }

    assignNewSceneId(): CorrelationId {
        return this.stage.assignNewSceneId();
    }

    currentSceneId(): CorrelationId {
        return this.stage.currentSceneId();
    }

    assignNewActivityId(activityDetails: ActivityDetails): CorrelationId {
        return this.stage.assignNewActivityId(activityDetails);
    }

    createError<RE extends RuntimeError>(errorType: new (...args: any[]) => RE, options: ErrorOptions): RE {
        return this.stage.createError(errorType, options);
    }

    /**
     * @package
     */
    waitForNextCue(): Promise<void> {
        return this.stage.waitForNextCue();
    }

    cwd(): Path {
        return this.workingDirectory;
    }
}
