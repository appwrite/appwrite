import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import { CorrelationId, Description, Name } from '../../model';
import { Timestamp } from '../../screenplay';
import { AsyncOperationAttempted } from '../AsyncOperationAttempted';

/**
 * Emitted when an [`Actor`](https://serenity-js.org/api/core/class/Actor/) is dismissed
 * either upon the [`SceneFinishes`](https://serenity-js.org/api/core-events/class/SceneFinishes/) event
 * for actors initialised within the scope of a test scenario,
 * or upon the [`TestRunFinishes`](https://serenity-js.org/api/core-events/class/TestRunFinishes/) event
 * for actors initialised within the scope of a test suite.
 *
 * @group Events
 */
export class ActorStageExitAttempted extends AsyncOperationAttempted {
    static fromJSON(o: JSONObject): ActorStageExitAttempted {
        return new ActorStageExitAttempted(
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
        ensure('actor', actor, isDefined());
        super(new Name('Stage'), new Description(`Actor ${ actor.value } exits the stage`), correlationId, timestamp);
    }
}
