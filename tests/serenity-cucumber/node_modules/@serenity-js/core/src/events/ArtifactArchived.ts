import type { JSONObject } from 'tiny-types';
import { ensure, isDefined } from 'tiny-types';

import { Path } from '../io';
import type { ArtifactType} from '../model';
import { Artifact, CorrelationId, Name } from '../model';
import { Timestamp } from '../screenplay';
import { DomainEvent } from './DomainEvent';

/**
 * @group Events
 */
export class ArtifactArchived extends DomainEvent {
    static fromJSON(o: JSONObject): ArtifactArchived {
        return new ArtifactArchived(
            CorrelationId.fromJSON(o.sceneId as string),
            Name.fromJSON(o.name as string),
            Artifact.ofType(o.type as string),
            Path.fromJSON(o.path as string),
            Timestamp.fromJSON(o.artifactTimestamp as string),
            Timestamp.fromJSON(o.timestamp as string),
        );
    }

    constructor(
        public readonly sceneId: CorrelationId,
        public readonly name: Name,
        public readonly type: ArtifactType,
        public readonly path: Path,
        public readonly artifactTimestamp: Timestamp,
        timestamp?: Timestamp,
    ) {
        super(timestamp);

        ensure('sceneId', sceneId, isDefined());
        ensure('name', name, isDefined());
        ensure('type', type, isDefined());
        ensure('path', path, isDefined());
        ensure('artifactTimestamp', artifactTimestamp, isDefined());
    }

    toJSON(): JSONObject {
        return {
            sceneId: this.sceneId.toJSON(),
            name: this.name.toJSON(),
            type: this.type.name,
            path: this.path.toJSON(),
            createdAt: this.artifactTimestamp.toJSON(),
            timestamp: this.timestamp.toJSON(),
            artifactTimestamp: this.artifactTimestamp.toJSON(),
        };
    }
}
