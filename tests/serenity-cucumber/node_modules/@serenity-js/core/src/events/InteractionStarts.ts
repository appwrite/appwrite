import type { JSONObject } from 'tiny-types';

import { ActivityDetails, CorrelationId } from '../model';
import { Timestamp } from '../screenplay';
import { ActivityStarts } from './ActivityStarts';

/**
 * Emitted when an [`Interaction`](https://serenity-js.org/api/core/class/Interaction/) starts.
 *
 * @group Events
 */
export class InteractionStarts extends ActivityStarts {
    static fromJSON(o: JSONObject): InteractionStarts {
        return new InteractionStarts(
            CorrelationId.fromJSON(o.sceneId as string),
            CorrelationId.fromJSON(o.activityId as string),
            ActivityDetails.fromJSON(o.details as JSONObject),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }
}
