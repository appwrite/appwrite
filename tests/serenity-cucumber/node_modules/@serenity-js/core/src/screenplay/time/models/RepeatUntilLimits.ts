import type { Duration } from './Duration';

/**
 * @group Time
 */
export interface RepeatUntilLimits<Result> {
    exitCondition?: (result: Result) => boolean,
    maxInvocations?: number,
    delayBetweenInvocations?: (i: number) => Duration,
    timeout?: Duration
    errorHandler?: (error: Error, result?: Result) => void;
}
