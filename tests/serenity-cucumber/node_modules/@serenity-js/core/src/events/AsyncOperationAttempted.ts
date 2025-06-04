import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import { CorrelationId, Description, Name } from '../model';
import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * @group Events
 */
export class AsyncOperationAttempted extends DomainEvent {
    static fromJSON(o: JSONObject): AsyncOperationAttempted {
        return new AsyncOperationAttempted(
            Name.fromJSON(o.name as string),
            Description.fromJSON(o.description as string),
            CorrelationId.fromJSON(o.correlationId as string),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly name: Name,
        public readonly description: Description,
        public readonly correlationId: CorrelationId,
        timestamp?: Timestamp,
    ) {
        super(timestamp);
        ensure('name', name, isDefined());
        ensure('description', description, isDefined());
        ensure('correlationId', correlationId, isDefined());
    }
}
