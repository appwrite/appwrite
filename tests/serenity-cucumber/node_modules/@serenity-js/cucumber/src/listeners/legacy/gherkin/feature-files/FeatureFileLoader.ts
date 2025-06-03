import type { Path } from '@serenity-js/core/lib/io';

import { Cache } from './Cache';
import type { FeatureFileMap } from './FeatureFileMap';
import type { FeatureFileMapper } from './FeatureFileMapper';
import type { FeatureFileParser } from './FeatureFileParser';

/**
 * @private
 */
export class FeatureFileLoader {
    constructor(
        private readonly parser: FeatureFileParser,
        private readonly mapper: FeatureFileMapper,
        private readonly cache: Cache<Path, FeatureFileMap> = new Cache(),
    ) {
    }

    load(path: Path): Promise<FeatureFileMap> {
        if (this.cache.has(path)) {
            return Promise.resolve(this.cache.get(path));
        }

        return this.parser.parse(path)
            .then(document => this.mapper.map(document, path))  // eslint-disable-line unicorn/no-array-method-this-argument
            .then(map => {
                this.cache.set(path, map);
                return this.cache.get(path);
            });
    }
}
