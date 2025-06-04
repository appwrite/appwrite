/// <reference types="node" />
import { IGherkinOptions } from '@cucumber/gherkin';
import * as messages from '@cucumber/messages';
import { Readable } from 'stream';
export interface IGherkinStreamOptions extends IGherkinOptions {
    relativeTo?: string;
}
declare function fromPaths(paths: readonly string[], options: IGherkinStreamOptions): Readable;
declare function fromSources(envelopes: readonly messages.Envelope[], options: IGherkinOptions): Readable;
declare const _default: {
    fromPaths: typeof fromPaths;
    fromSources: typeof fromSources;
};
export default _default;
//# sourceMappingURL=GherkinStreams.d.ts.map