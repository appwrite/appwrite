import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import { CorrelationId } from '../../model';
import { type SerialisedActor, Timestamp } from '../../screenplay';
import { DomainEvent } from '../DomainEvent';

/**
 * Emitted upon the [`SceneFinishes`](https://serenity-js.org/api/core-events/class/SceneFinishes/) and
 * [`TestRunFinishes`](https://serenity-js.org/api/core-events/class/TestRunFinishes/) events
 * to notify the [stage crew members](https://serenity-js.org/api/core/interface/StageCrewMember/)
 * about the final state of the [actors](https://serenity-js.org/api/core/class/Actor/) and their abilities
 * before they're [released](https://serenity-js.org/api/core/interface/Discardable/).
 *
 * @group Events
 */
export class ActorStageExitStarts extends DomainEvent {
    static fromJSON(o: JSONObject): ActorStageExitStarts {
        return new ActorStageExitStarts(
            CorrelationId.fromJSON(o.sceneId as string),
            o.actor as unknown as SerialisedActor,
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly sceneId: CorrelationId,
        public readonly actor: SerialisedActor,
        timestamp?: Timestamp,
    ) {
        super(timestamp);
        ensure('sceneId', sceneId, isDefined());
        ensure('actor', actor, isDefined());
    }
}
