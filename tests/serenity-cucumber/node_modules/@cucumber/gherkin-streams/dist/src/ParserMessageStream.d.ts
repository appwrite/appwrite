/// <reference types="node" />
import { IGherkinOptions } from '@cucumber/gherkin';
import * as messages from '@cucumber/messages';
import { Transform, TransformCallback } from 'stream';
/**
 * Stream that reads Source messages and writes GherkinDocument and Pickle messages.
 */
export default class ParserMessageStream extends Transform {
    private readonly options;
    constructor(options: IGherkinOptions);
    _transform(envelope: messages.Envelope, encoding: string, callback: TransformCallback): void;
}
//# sourceMappingURL=ParserMessageStream.d.ts.map