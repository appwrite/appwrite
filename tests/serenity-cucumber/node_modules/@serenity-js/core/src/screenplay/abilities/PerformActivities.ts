import { match } from 'tiny-types';

import { AssertionError, ImplementationPendingError, TestCompromisedError } from '../../errors';
import type { EmitsDomainEvents } from '../../events';
import { InteractionFinished, InteractionStarts, TaskFinished, TaskStarts } from '../../events';
import { type FileSystemLocation, ValueInspector } from '../../io';
import type { Outcome, ProblemIndication } from '../../model';
import {
    ActivityDetails,
    ExecutionCompromised,
    ExecutionFailedWithAssertionError,
    ExecutionFailedWithError,
    ExecutionSuccessful,
    ImplementationPending,
    Name
} from '../../model';
import type { PerformsActivities } from '../activities/PerformsActivities';
import type { Activity } from '../Activity';
import { Interaction } from '../Interaction';
import type { AnswersQuestions } from '../questions';
import { Ability } from './Ability';
import type { UsesAbilities } from './UsesAbilities';

/**
 * An [`Ability`](https://serenity-js.org/api/core/class/Ability/) that enables an [`Actor`](https://serenity-js.org/api/core/class/Actor/) to perform a given [`Activity`](https://serenity-js.org/api/core/class/Activity/).
 *
 * [`PerformActivities`](https://serenity-js.org/api/core/class/PerformActivities/) is used internally by [`Actor.attemptsTo`](https://serenity-js.org/api/core/class/Actor/#attemptsTo), and it is unlikely you'll ever need to use it directly in your code.
 * That is, unless you're building a custom Serenity/JS extension and want to override the default behaviour of the framework,
 * in which case you should check out the [Contributor's Guide](https://serenity-js.org/community/contributing).
 *
 * @group Abilities
 */
export class PerformActivities extends Ability {
    constructor(
        protected readonly actor: AnswersQuestions & UsesAbilities & PerformsActivities & { name: string },
        protected readonly stage: EmitsDomainEvents,
    ) {
        super();
    }

    async perform(activity: Activity): Promise<void> {
        const sceneId    = this.stage.currentSceneId();
        const details    = this.detailsOf(this.nameOf(activity), activity.instantiationLocation());
        const activityId = this.stage.assignNewActivityId(details);

        const [ activityStarts, activityFinished ] = activity instanceof Interaction
            ? [ InteractionStarts, InteractionFinished ]
            : [ TaskStarts, TaskFinished ];

        try {
            this.stage.announce(
                new activityStarts(
                    sceneId,
                    activityId,
                    details,
                    this.stage.currentTime()
                )
            );

            await activity.performAs(this.actor);

            const name = await activity.describedBy(this.actor);

            this.stage.announce(
                new activityFinished(
                    sceneId,
                    activityId,
                    this.detailsOf(name, activity.instantiationLocation()),
                    new ExecutionSuccessful(),
                    this.stage.currentTime()
                )
            );
        }
        catch (error) {
            this.stage.announce(new activityFinished(sceneId, activityId, details, this.outcomeFor(error), this.stage.currentTime()));
            throw error;
        }
        finally {
            await this.stage.waitForNextCue();
        }
    }
    protected outcomeFor(error: Error | any): Outcome {
        return match<Error, ProblemIndication>(error)
            .when(ImplementationPendingError, _ => new ImplementationPending(error))
            .when(TestCompromisedError, _ => new ExecutionCompromised(error))
            .when(AssertionError, _ => new ExecutionFailedWithAssertionError(error))
            .when(Error, _ =>
                /AssertionError/.test(error.constructor.name) // mocha
                    ? new ExecutionFailedWithAssertionError(error)
                    : new ExecutionFailedWithError(error))
            .else(_ => new ExecutionFailedWithError(error));
    }

    private detailsOf(name: string, instantiationLocation: FileSystemLocation): ActivityDetails {
        return new ActivityDetails(
            new Name(name),
            instantiationLocation,
        )
    }

    protected nameOf(activity: Activity): string {
        const template = ValueInspector.hasItsOwnToString(activity)
            ? activity.toString()
            : `#actor performs ${ activity.constructor.name }`;

        return template.replaceAll('#actor', this.actor.name);
    }
}
