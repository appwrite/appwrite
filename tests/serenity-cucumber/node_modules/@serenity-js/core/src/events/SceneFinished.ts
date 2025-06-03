import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import type { SerialisedOutcome } from '../model';
import { CorrelationId, Outcome, ScenarioDetails } from '../model';
import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * @group Events
 */
export class SceneFinished extends DomainEvent {
    static fromJSON(o: JSONObject): SceneFinished {
        return new SceneFinished(
            CorrelationId.fromJSON(o.sceneId as string),
            ScenarioDetails.fromJSON(o.details as JSONObject),
            Outcome.fromJSON(o.outcome as SerialisedOutcome),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly sceneId: CorrelationId,
        public readonly details: ScenarioDetails,
        public readonly outcome: Outcome,
        timestamp?: Timestamp,
    ) {
        super(timestamp);
        ensure('sceneId', sceneId, isDefined());
        ensure('details', details, isDefined());
        ensure('outcome', outcome, isDefined());
    }
}
