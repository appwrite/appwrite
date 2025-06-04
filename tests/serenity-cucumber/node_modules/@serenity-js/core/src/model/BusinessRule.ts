import type { JSONObject} from 'tiny-types';
import { ensure, isDefined, TinyType } from 'tiny-types';

import { Description } from './Description';
import { Name } from './Name';

export class BusinessRule extends TinyType {
    static fromJSON(o: JSONObject): BusinessRule {
        return new BusinessRule(
            Name.fromJSON(o.name as string),
            Description.fromJSON(o.description as string),
        );
    }

    constructor(
        public readonly name: Name,
        public readonly description: Description,
    ) {
        super();
        ensure('name', name, isDefined());
        ensure('description', description, isDefined());
    }
}
