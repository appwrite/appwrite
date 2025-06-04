/* eslint-disable unicorn/prevent-abbreviations */
import type { FileSystem } from '@serenity-js/core/lib/io';

import type { OutputDescriptor } from './OutputDescriptor';
import type { SerenityFormatterOutput } from './SerenityFormatterOutput';
import { TempFileOutputDescriptor } from './TempFileOutputDescriptor';

/**
 * @group Integration
 */
export class TempFileOutput implements SerenityFormatterOutput {    // eslint-disable-line unicorn/prevent-abbreviations
    constructor(private readonly fs: FileSystem) {
    }

    get(): OutputDescriptor {
        return new TempFileOutputDescriptor(this.fs);
    }
}
