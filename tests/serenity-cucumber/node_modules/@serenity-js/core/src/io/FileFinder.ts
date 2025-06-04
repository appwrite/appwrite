import fg from 'fast-glob';

import { Path } from './Path';

export class FileFinder {
    constructor(private readonly cwd: Path) {
    }

    filesMatching(globPatterns: string[] | string | undefined): Path[] {
        if (! globPatterns) {
            return [];
        }

        return fg.sync(globPatterns, {
            cwd: this.cwd.value,
            absolute: true,
            unique: true,
        }).map((value: string) => new Path(value));
    }
}
