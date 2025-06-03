import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import { CorrelationId, Description } from '../model';
import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * @group Events
 */
export class FeatureNarrativeDetected extends DomainEvent {
    public static fromJSON(o: JSONObject): FeatureNarrativeDetected {
        return new FeatureNarrativeDetected(
            CorrelationId.fromJSON(o.sceneId as string),
            Description.fromJSON(o.description as string),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly sceneId: CorrelationId,
        public readonly description: Description,
        timestamp?: Timestamp,
    ) {
        super(timestamp);
        ensure('sceneId', sceneId, isDefined());
        ensure('description', description, isDefined());
    }
}
