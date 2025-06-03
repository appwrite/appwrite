import type { JSONObject} from 'tiny-types';
import { ensure, isDefined, TinyType } from 'tiny-types';

import { FileSystemLocation } from '../io/FileSystemLocation';
import { Name } from './Name';

export class ActivityDetails extends TinyType {
    static fromJSON(o: JSONObject): ActivityDetails {
        return new ActivityDetails(
            Name.fromJSON(o.name as string),
            FileSystemLocation.fromJSON(o.location as JSONObject),
        );
    }

    constructor(
        public readonly name: Name,
        public readonly location: FileSystemLocation,
    ) {
        super();
        ensure('name', name, isDefined())
        ensure('location', location, isDefined())
    }

    toJSON(): { name: string, location: { path: string, line: number, column: number } } {
        return {
            name: this.name.value,
            location: this.location.toJSON() as { path: string, line: number, column: number },
        }
    }
}
