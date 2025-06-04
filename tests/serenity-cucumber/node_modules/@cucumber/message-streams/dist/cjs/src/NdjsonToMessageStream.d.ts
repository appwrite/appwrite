/// <reference types="node" />
import { Envelope } from '@cucumber/messages';
import { Transform, TransformCallback } from 'stream';
/**
 * Transforms an NDJSON stream to a stream of message objects
 */
export default class NdjsonToMessageStream extends Transform {
    private readonly parseLine;
    private buffer;
    /**
     * Create a new stream
     *
     * @param parseLine a function that parses a line. This function may ignore a line by returning null.
     */
    constructor(parseLine?: (line: string) => Envelope | null);
    _transform(chunk: string, encoding: string, callback: TransformCallback): void;
    _flush(callback: TransformCallback): void;
}
//# sourceMappingURL=NdjsonToMessageStream.d.ts.map