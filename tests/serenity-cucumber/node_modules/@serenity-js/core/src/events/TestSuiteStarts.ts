import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import { TestSuiteDetails } from '../model';
import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * @group Events
 */
export class TestSuiteStarts extends DomainEvent {
    static fromJSON(o: JSONObject): TestSuiteStarts {
        return new TestSuiteStarts(
            TestSuiteDetails.fromJSON(o.details as JSONObject),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly details: TestSuiteDetails,
        timestamp?: Timestamp,
    ) {
        super(timestamp);
        ensure('details', details, isDefined());
    }
}
