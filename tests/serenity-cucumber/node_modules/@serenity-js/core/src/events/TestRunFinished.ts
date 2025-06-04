import type { JSONObject } from 'tiny-types';

import type { SerialisedOutcome } from '../model';
import { Outcome } from '../model';
import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * Emitted when all the test scenarios have finished running.
 *
 * @group Events
 */
export class TestRunFinished extends DomainEvent {
    static fromJSON(o: JSONObject): TestRunFinished {
        return new TestRunFinished(
            Outcome.fromJSON(o.outcome as SerialisedOutcome),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly outcome: Outcome,
        timestamp?: Timestamp,
    ) {
        super(timestamp);
    }
}
