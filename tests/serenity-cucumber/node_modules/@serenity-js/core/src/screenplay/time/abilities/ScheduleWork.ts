import type { Discardable, SerialisedAbility } from '../../abilities';
import { Ability } from '../../abilities';
import type { Clock, DelayedCallback, Duration, RepeatUntilLimits } from '../models';
import { Scheduler } from '../models';

/**
 * An [`Ability`](https://serenity-js.org/api/core/class/Ability/) that enables an [`Actor`](https://serenity-js.org/api/core/class/Actor/) to schedule a callback function
 * to be executed with a delay, or until some condition is met.
 *
 * Used internally by the [interaction](https://serenity-js.org/api/core/class/Interaction/) to [`Wait`](https://serenity-js.org/api/core/class/Wait/).
 *
 * @experimental
 *
 * @group Time
 */
export class ScheduleWork extends Ability implements Discardable {

    private readonly scheduler: Scheduler;

    constructor(clock: Clock, interactionTimeout: Duration) {
        super();
        this.scheduler = new Scheduler(clock, interactionTimeout);
    }

    /**
     * @param callback
     * @param limits
     */
    repeatUntil<Result>(
        callback: DelayedCallback<Result>,
        limits?: RepeatUntilLimits<Result>,
    ): Promise<Result> {
        return this.scheduler.repeatUntil(callback, limits);
    }

    waitFor(delay: Duration): Promise<void> {
        return this.scheduler.waitFor(delay);
    }

    discard(): void {
        this.scheduler.stop();
    }

    override toJSON(): SerialisedAbility {
        return {
            ...super.toJSON(),
            options: {
                scheduler: this.scheduler.toJSON(),
            },
        };
    }
}
