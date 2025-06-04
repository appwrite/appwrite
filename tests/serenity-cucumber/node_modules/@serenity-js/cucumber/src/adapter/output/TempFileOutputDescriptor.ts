/* eslint-disable unicorn/prevent-abbreviations */
import type { FileSystem, Path } from '@serenity-js/core/lib/io';

import type { OutputDescriptor } from './OutputDescriptor';

/**
 * Cucumber.js allows [max 1 formatter per output](https://github.com/cucumber/cucumber-js/blob/625fab034eea768bf74f7a46993a57182204ddf6/src/cli/index.ts#L83-L140)
 * and doesn't allow writing to \\.\NUL on Windows (equivalent of *nix /dev/null) because of the [check in OptionSplitter](https://github.com/cucumber/cucumber-js/blob/625fab034eea768bf74f7a46993a57182204ddf6/src/cli/option_splitter.ts#L3)
 * so instead I create a dummy temp file, which is deleted when the test run is finished.
 *
 * @group Integration
 */
export class TempFileOutputDescriptor implements OutputDescriptor {  // eslint-disable-line unicorn/prevent-abbreviations
    private readonly path: Path;

    constructor(private readonly fileSystem: FileSystem) {
        this.path = this.fileSystem.tempFilePath('serenity-');
    }

    value(): string {
        return this.path.value;
    }

    cleanUp(): Promise<void> {
        return this.fileSystem.remove(this.path);
    }
}
