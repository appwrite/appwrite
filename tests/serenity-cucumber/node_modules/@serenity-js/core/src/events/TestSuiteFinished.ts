import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import type { SerialisedOutcome} from '../model';
import { Outcome, TestSuiteDetails } from '../model';
import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * @group Events
 */
export class TestSuiteFinished extends DomainEvent {
    static fromJSON(o: JSONObject): TestSuiteFinished {
        return new TestSuiteFinished(
            TestSuiteDetails.fromJSON(o.details as JSONObject),
            Outcome.fromJSON(o.outcome as SerialisedOutcome),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly details: TestSuiteDetails,
        public readonly outcome: Outcome,
        timestamp?: Timestamp,
    ) {
        super(timestamp);
        ensure('details', details, isDefined());
        ensure('outcome', outcome, isDefined());
    }
}
