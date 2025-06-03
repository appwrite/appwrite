import type { JSONObject } from 'tiny-types';

import type { SerialisedOutcome } from '../model';
import { ActivityDetails, CorrelationId, Outcome } from '../model';
import { Timestamp } from '../screenplay';
import { ActivityFinished } from './ActivityFinished';

/**
 * Emitted when an [`Interaction`](https://serenity-js.org/api/core/class/Interaction/) is finished.
 * [`StageCrewMember`](https://serenity-js.org/api/core/interface/StageCrewMember/) instances listen
 * to this event to report on the outcome of the interaction, or perform any additional follow-up activities,
 * such as taking a screenshot.
 *
 * @group Events
 */
export class InteractionFinished extends ActivityFinished {
    static fromJSON(o: JSONObject): InteractionFinished {
        return new InteractionFinished(
            CorrelationId.fromJSON(o.sceneId as string),
            CorrelationId.fromJSON(o.activityId as string),
            ActivityDetails.fromJSON(o.details as JSONObject),
            Outcome.fromJSON(o.outcome as SerialisedOutcome),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }
}
