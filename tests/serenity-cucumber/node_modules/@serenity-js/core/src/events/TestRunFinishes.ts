import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * Emitted when the last test in the test suite has finished running
 * and it's time for any last-minute reporting activities to take place.
 *
 * @group Events
 */
export class TestRunFinishes extends DomainEvent {
    static fromJSON(v: string): TestRunFinishes {
        return new TestRunFinishes(
            Timestamp.fromJSON(v as string),
        );
    }

    constructor(timestamp?: Timestamp) {
        super(timestamp);
    }
}
