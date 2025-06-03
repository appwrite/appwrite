import type { JSONValue } from 'tiny-types';

import { Artifact } from '../Artifact';

export class JSONData extends Artifact {
    static fromJSON(value: JSONValue): JSONData {
        return new JSONData(Buffer.from(JSON.stringify(value, undefined, 0), 'utf8').toString('base64'));
    }

    map<O>(fn: (decodedValue: JSONValue) => O): O {
        return fn(JSON.parse(Buffer.from(this.base64EncodedValue, 'base64').toString('utf8')));
    }
}
