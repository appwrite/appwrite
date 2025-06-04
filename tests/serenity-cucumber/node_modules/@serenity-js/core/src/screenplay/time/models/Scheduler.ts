import type { JSONObject } from 'tiny-types';

import { OperationInterruptedError, TimeoutExpiredError } from '../../../errors';
import type { Clock } from './Clock';
import type { DelayedCallback } from './DelayedCallback';
import { Duration } from './Duration';
import type { RepeatUntilLimits } from './RepeatUntilLimits';
import type { Timestamp } from './Timestamp';

/**
 * @group Time
 */
export class Scheduler {

    private scheduledOperations: Array<ScheduledOperation<unknown>> = [];

    /**
     * @param clock
     * @param interactionTimeout
     *  The maximum amount of time to give to a callback to complete before throwing an error
     */
    constructor(
        private readonly clock: Clock,
        private readonly interactionTimeout: Duration,
    ) {
    }

    toJSON(): JSONObject {
        return {
            clock: this.clock.toJSON(),
            interactionTimeout: this.interactionTimeout.toJSON(),
        }
    }

    /**
     * Schedules a callback function to be invoked after a delay
     *
     * @param delay
     * @param callback
     */
    after<Result>(delay: Duration, callback: DelayedCallback<Result>): Promise<Result> {
        return this.repeatUntil<Result>(
            callback,
            {
                maxInvocations: 1,
                delayBetweenInvocations: () => delay,
                timeout: this.interactionTimeout.plus(delay),
            },
        );
    }

    /**
     * Returns a `Promise` to be resolved after a `delay`
     *
     * @param delay
     */
    waitFor(delay: Duration): Promise<void> {
        return this.repeatUntil<void>(
            () => void 0,
            {
                maxInvocations: 1,
                delayBetweenInvocations: () => delay,

                // make sure waitFor doesn't get terminated before it's resolved
                timeout: this.interactionTimeout.plus(delay),
            },
        );
    }

    /**
     * Schedules a callback function to be repeated, according to configured limits.
     *
     * @param callback
     * @param limits
     */
    async repeatUntil<Result>(
        callback: DelayedCallback<Result>,
        limits: RepeatUntilLimits<Result> = {},
    ): Promise<Result> {

        const {
            maxInvocations          = Number.POSITIVE_INFINITY,
            delayBetweenInvocations = noDelay,
            timeout                 = this.interactionTimeout,
            exitCondition           = noEarlyExit,
            errorHandler            = rethrowErrors,
        } = limits;

        const operation = new ScheduledOperation(
            this.clock,
            callback,
            {
                exitCondition,
                maxInvocations,
                delayBetweenInvocations,
                timeout,
                errorHandler,
            }
        );

        this.scheduledOperations.push(operation);
        return operation.start()
    }

    stop(): void {
        for (const operation of this.scheduledOperations) {
            operation.cancel();
        }
    }
}

class ScheduledOperation<Result> {
    private currentInvocation   = 0;
    private invocationsLeft     = 0;
    private startedAt: Timestamp;
    private lastResult: Result;

    private isCancelled = false;

    constructor(
        private readonly clock: Clock,
        private readonly callback: DelayedCallback<Result>,
        private readonly limits: RepeatUntilLimits<Result> = {},
    ) {
    }

    async start(): Promise<Result> {
        this.currentInvocation  = 0;
        this.invocationsLeft    = this.limits.maxInvocations;
        this.startedAt          = this.clock.now();

        return await this.poll();
    }

    private async poll(): Promise<Result> {
        await this.clock.waitFor(this.limits.delayBetweenInvocations(this.currentInvocation));

        if (this.isCancelled) {
            throw new OperationInterruptedError('Scheduler stopped before executing callback');
        }

        const receipt = await this.invoke();

        if (receipt.hasCompleted) {
            return receipt.result;
        }

        this.currentInvocation++;
        this.invocationsLeft--;

        return await this.poll();
    }

    private async invoke(): Promise<{ result?: Result, error?: Error, hasCompleted: boolean }> {

        const timeoutExpired = this.startedAt.plus(this.limits.timeout).isBefore(this.clock.now());
        const isLastInvocation = this.invocationsLeft === 1;

        if (this.invocationsLeft === 0) {
            return {
                result: this.lastResult,
                hasCompleted: true,
            };
        }

        try {
            if (timeoutExpired) {
                throw new TimeoutExpiredError(`Timeout of ${ this.limits.timeout } has expired`);
            }

            this.lastResult = await this.callback({ currentTime: this.clock.now(), i: this.currentInvocation });

            return {
                result:       this.lastResult,
                hasCompleted: this.limits.exitCondition(this.lastResult) || isLastInvocation,
            }
        }
        catch(error) {

            this.limits.errorHandler(error, this.lastResult);

            // if the errorHandler didn't throw, it's a recoverable error
            return {
                result:       this.lastResult,
                error,
                hasCompleted: isLastInvocation,
            }
        }
    }

    cancel(): void {
        this.isCancelled = true;
    }
}

function noDelay() {
    return Duration.ofMilliseconds(0);
}

function noEarlyExit() {
    return false;
}

function rethrowErrors(error: Error) {
    throw error;
}
