import type { Serenity} from '@serenity-js/core';
import { AssertionError, ImplementationPendingError, TestCompromisedError } from '@serenity-js/core';
import type {
    Outcome,
    ProblemIndication} from '@serenity-js/core/lib/model';
import {
    ExecutionCompromised,
    ExecutionFailedWithAssertionError,
    ExecutionFailedWithError,
    ExecutionSkipped,
    ExecutionSuccessful,
    ImplementationPending
} from '@serenity-js/core/lib/model';

import { AmbiguousStepDefinitionError } from '../../../errors';

/**
 * @package
 */
export class ResultMapper {

    constructor(private readonly serenity: Serenity) {
    }

    outcomeFor(status: string, maybeError: Error | string | undefined): Outcome {
        const error = this.errorFrom(maybeError);

        if (this.isTimeoutError(error)) {
            return new ExecutionFailedWithError(error);
        }

        switch (true) {
            case status === 'undefined':
                return new ImplementationPending(new ImplementationPendingError('Step not implemented'));

            case status === 'ambiguous':
                if (! error) {
                    // Only the step result contains the "ambiguous step def error", the scenario itself doesn't
                    return new ExecutionFailedWithError(new AmbiguousStepDefinitionError('Multiple step definitions match'));
                }

                return new ExecutionFailedWithError(error);

            case status === 'failed':
                return this.problemIndicationOutcomeFromError(error);

            case status === 'pending':
                return new ImplementationPending(new ImplementationPendingError('Step not implemented'));

            case status === 'skipped':
                return new ExecutionSkipped();

            // case status === 'passed':
            default:
                return new ExecutionSuccessful();
        }
    }

    errorFrom(error: Error | string | undefined): Error | undefined {
        switch (typeof error) {
            case 'string':   return new Error(error as string);
            case 'object':   return error as Error;
            case 'function': return error as Error;
            default:         return void 0;
        }
    }

    private isTimeoutError(error?: Error) {
        return error && /timed out/.test(error.message);
    }

    private isANonSerenityAssertionError(error?: Error): error is Error & { name: string, message: string, expected: any, actual: any } {
        return error instanceof Error
            && error.name === 'AssertionError'
            && error.message && hasOwnProperty(error, 'expected')
            && hasOwnProperty(error, 'actual');
    }

    private problemIndicationOutcomeFromError(error?: Error): ProblemIndication {
        if (error instanceof AssertionError) {
            return new ExecutionFailedWithAssertionError(error as AssertionError);
        }

        if (this.isANonSerenityAssertionError(error)) {
            return new ExecutionFailedWithAssertionError(
                this.serenity.createError(AssertionError, {
                    message: error.message,
                    diff: {
                        expected: error.expected,
                        actual: error.actual,
                    },
                    cause: error,
                }),
            );
        }

        if (error instanceof TestCompromisedError) {
            return new ExecutionCompromised(error as TestCompromisedError);
        }

        return new ExecutionFailedWithError(error);
    }
}

/**
 * @private
 */
function hasOwnProperty(value: any, fieldName: string): boolean {
    return Object.prototype.hasOwnProperty.call(value, fieldName);
}
