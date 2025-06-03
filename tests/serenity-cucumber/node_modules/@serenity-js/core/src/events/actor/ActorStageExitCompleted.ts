import { ensure, isDefined, type JSONObject } from 'tiny-types';

import { CorrelationId, Name } from '../../model';
import { Timestamp } from '../../screenplay';
import { AsyncOperationCompleted } from '../AsyncOperationCompleted';

/**
 * Emitted when an [`Actor`](https://serenity-js.org/api/core/class/Actor/) and its abilities
 * are correctly [released](https://serenity-js.org/api/core/interface/Discardable/) either
 * upon the [`SceneFinishes`](https://serenity-js.org/api/core-events/class/SceneFinishes/) event
 * for actors initialised within the scope of a test scenario,
 * or upon the [`TestRunFinishes`](https://serenity-js.org/api/core-events/class/TestRunFinishes/) event
 * for actors initialised within the scope of a test suite.
 *
 * @group Events
 */
export class ActorStageExitCompleted extends AsyncOperationCompleted {
    static fromJSON(o: JSONObject): ActorStageExitCompleted {
        return new ActorStageExitCompleted(
            CorrelationId.fromJSON(o.correlationId as string),
            Name.fromJSON(o.actor as string),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        correlationId: CorrelationId,
        public readonly actor: Name,
        timestamp?: Timestamp,
    ) {
        super(correlationId, timestamp);
        ensure('actor', actor, isDefined());
    }
}
