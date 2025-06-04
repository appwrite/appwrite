import { RuntimeError } from '@serenity-js/core/lib/errors';

/**
 * @private
 */
export class UnableToReadFeatureFileError extends RuntimeError {
    constructor(message: string, cause?: Error) {
        super(UnableToReadFeatureFileError, message, cause);
    }
}
