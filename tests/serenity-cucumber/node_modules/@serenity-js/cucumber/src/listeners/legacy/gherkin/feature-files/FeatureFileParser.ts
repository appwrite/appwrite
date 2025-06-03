import type { Path } from '@serenity-js/core/lib/io';
import * as fs from 'fs';

import type { GherkinDocument } from '../nodes';
import { UnableToParseFeatureFileError, UnableToReadFeatureFileError } from './errors';

/**
 * @private
 */
export class FeatureFileParser {
    constructor(private readonly gherkinParser: { parse: (feature: string) => GherkinDocument }) {
    }

    parse(uri: Path): Promise<GherkinDocument> {
        return new Promise((resolve, reject) => {
            fs.readFile(uri.value, (error: NodeJS.ErrnoException | undefined, data: Buffer) => {
                if (error) {
                    return reject(
                        new UnableToReadFeatureFileError(`Could not read feature file at "${ uri.value }"`, error),
                    );
                }

                try {
                    return resolve(this.gherkinParser.parse(data.toString('utf8')));
                }
                catch (parseError) {
                    return reject(
                        new UnableToParseFeatureFileError(`Could not parse feature file at "${ uri.value }"`, parseError),
                    );
                }
            });
        });
    }
}
