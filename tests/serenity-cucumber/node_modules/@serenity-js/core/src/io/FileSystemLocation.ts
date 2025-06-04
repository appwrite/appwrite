import type { JSONObject} from 'tiny-types';
import { TinyType } from 'tiny-types';

import { Path } from './Path';

export class FileSystemLocation extends TinyType {
    static fromJSON(o: JSONObject): FileSystemLocation {
        return new FileSystemLocation(
            Path.fromJSON(o.path as string),
            o.line as number,
            o.column as number,
        );
    }

    constructor(
        public readonly path: Path,
        public readonly line?: number,
        public readonly column?: number,
    ) {
        super();
    }
}
