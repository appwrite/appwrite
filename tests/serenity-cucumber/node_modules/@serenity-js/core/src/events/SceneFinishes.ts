import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import { CorrelationId } from '../model';
import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * Emitted by a Serenity/JS test runner adapter, right before a test and all its associated test hooks finish.
 * Triggers any clean-up operations that might be required, such as discarding of
 * the [discardable](https://serenity-js.org/api/core/interface/Discardable/) abilities.
 *
 * @group Events
 */
export class SceneFinishes extends DomainEvent {
    static fromJSON(o: JSONObject): SceneFinishes {
        return new SceneFinishes(
            CorrelationId.fromJSON(o.sceneId as string),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly sceneId: CorrelationId,
        timestamp?: Timestamp,
    ) {
        super(timestamp);
        ensure('sceneId', sceneId, isDefined());
    }
}
