/// <reference types="node" />
import { Transform, TransformCallback } from 'stream';
/**
 * Stream that reads a string and writes a single Source message.
 */
export default class SourceMessageStream extends Transform {
    private readonly uri;
    private readonly relativeTo?;
    private buffer;
    constructor(uri: string, relativeTo?: string);
    _transform(chunk: Buffer, encoding: string, callback: TransformCallback): void;
    _flush(callback: TransformCallback): void;
}
//# sourceMappingURL=SourceMessageStream.d.ts.map