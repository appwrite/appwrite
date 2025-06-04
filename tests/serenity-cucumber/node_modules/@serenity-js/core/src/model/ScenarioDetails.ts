import type { JSONObject} from 'tiny-types';
import { ensure, isDefined, TinyType } from 'tiny-types';

import { FileSystemLocation } from '../io';
import { Category } from './Category';
import { Name } from './Name';

export class ScenarioDetails extends TinyType {
    static fromJSON(o: JSONObject): ScenarioDetails {
        return new ScenarioDetails(
            Name.fromJSON(o.name as string),
            Category.fromJSON(o.category as string),
            FileSystemLocation.fromJSON(o.location as JSONObject),
        );
    }

    constructor(
        public readonly name: Name,
        public readonly category: Category,
        public readonly location: FileSystemLocation,
    ) {
        super();
        ensure('scenario name', name, isDefined());
        ensure('scenario category', category, isDefined());
        ensure('scenario location', location, isDefined());
    }
}
