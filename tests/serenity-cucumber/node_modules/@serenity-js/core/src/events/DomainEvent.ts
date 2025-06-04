import { ensure, isDefined, TinyType } from 'tiny-types';

import { Timestamp } from '../screenplay';

/**
 * Represents an internal domain event that occurs during test execution.
 *
 * @group Events
 */
export abstract class DomainEvent extends TinyType {

    /**
     * @param timestamp
     */
    protected constructor(public readonly timestamp: Timestamp = new Timestamp()) {
        super();
        ensure('timestamp', timestamp, isDefined());
    }
}
