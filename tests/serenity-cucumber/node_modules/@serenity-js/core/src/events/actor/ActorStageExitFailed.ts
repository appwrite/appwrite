import type { JSONObject } from 'tiny-types';

import { ErrorSerialiser } from '../../errors';
import { CorrelationId } from '../../model';
import { Timestamp } from '../../screenplay';
import { AsyncOperationFailed } from '../AsyncOperationFailed';

/**
 * Emitted when [releasing](https://serenity-js.org/api/core/interface/Discardable/) an
 * [`Actor`](https://serenity-js.org/api/core/class/Actor/) or its abilities
 * resulted in an error either
 * upon the [`SceneFinishes`](https://serenity-js.org/api/core-events/class/SceneFinishes/) event
 * for actors initialised within the scope of a test scenario,
 * or upon the [`TestRunFinishes`](https://serenity-js.org/api/core-events/class/TestRunFinishes/) event
 * for actors initialised within the scope of a test suite.
 *
 * @group Events
 */
export class ActorStageExitFailed extends AsyncOperationFailed {
    static fromJSON(o: JSONObject): ActorStageExitFailed {
        return new ActorStageExitFailed(
            ErrorSerialiser.deserialise(o.error as string),
            CorrelationId.fromJSON(o.correlationId as string),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }
}
