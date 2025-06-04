import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import type { SerialisedArtifact } from '../model';
import { Artifact, CorrelationId, Name } from '../model';
import { Timestamp } from '../screenplay';
import { ArtifactGenerated } from './ArtifactGenerated';

/**
 * @group Events
 */
export class ActivityRelatedArtifactGenerated extends ArtifactGenerated {
    static fromJSON(o: JSONObject): ActivityRelatedArtifactGenerated {
        return new ActivityRelatedArtifactGenerated(
            CorrelationId.fromJSON(o.sceneId as string),
            CorrelationId.fromJSON(o.activityId as string),
            Name.fromJSON(o.name as string),
            Artifact.fromJSON(o.artifact as SerialisedArtifact),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        sceneId: CorrelationId,
        public readonly activityId: CorrelationId,
        name: Name,
        artifact: Artifact,
        timestamp?: Timestamp,
    ) {
        super(sceneId, name, artifact, timestamp);
        ensure('activityId', activityId, isDefined());
    }
}
