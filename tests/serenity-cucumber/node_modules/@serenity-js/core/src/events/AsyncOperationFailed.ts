import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import { ErrorSerialiser } from '../errors';
import { CorrelationId } from '../model';
import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * @group Events
 */
export class AsyncOperationFailed extends DomainEvent {
    static fromJSON(o: JSONObject): AsyncOperationFailed {
        return new AsyncOperationFailed(
            ErrorSerialiser.deserialise(o.error as string),
            CorrelationId.fromJSON(o.correlationId as string),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly error: Error,
        public readonly correlationId: CorrelationId,
        timestamp?: Timestamp,
    ) {
        super(timestamp);
        ensure('error', error, isDefined());
        ensure('correlationId', correlationId, isDefined());
    }

    toJSON(): JSONObject {
        return {
            correlationId: this.correlationId.toJSON(),
            error: ErrorSerialiser.serialise(this.error),
            timestamp: this.timestamp.toJSON(),
        };
    }
}
