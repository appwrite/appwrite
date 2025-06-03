import { RuntimeError } from '@serenity-js/core/lib/errors';

/**
 * @private
 */
export class UnableToRetrieveFeatureFileMap extends RuntimeError {
    constructor(message: string, cause?: Error) {
        super(UnableToRetrieveFeatureFileMap, message, cause);
    }
}
