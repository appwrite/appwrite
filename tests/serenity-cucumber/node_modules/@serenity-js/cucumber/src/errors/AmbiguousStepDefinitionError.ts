import { RuntimeError } from '@serenity-js/core/lib/errors';

/**
 * Thrown when more than one Cucumber step definition matches
 * a Cucumber step.
 */
export class AmbiguousStepDefinitionError extends RuntimeError {
    constructor(message: string, cause?: Error) {
        super(AmbiguousStepDefinitionError, message, cause);
    }
}
