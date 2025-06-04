import { TinyType } from 'tiny-types';

import type { ExpectationDetails } from './ExpectationDetails';

/**
 * An outcome of an [`Expectation`](https://serenity-js.org/api/core/class/Expectation/),
 * which could be either [met](https://serenity-js.org/api/core/class/ExpectationMet/) or [not met](https://serenity-js.org/api/core/class/ExpectationNotMet/).
 *
 * @group Expectations
 */
export class ExpectationOutcome extends TinyType {
    constructor(
        public readonly message: string,
        public readonly expectation: ExpectationDetails,
        public readonly expected: unknown,
        public readonly actual: unknown,
    ) {
        super();
    }
}
