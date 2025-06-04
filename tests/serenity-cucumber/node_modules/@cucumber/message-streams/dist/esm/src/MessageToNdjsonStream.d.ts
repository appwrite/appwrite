/// <reference types="node" />
import * as messages from '@cucumber/messages';
import { Transform, TransformCallback } from 'stream';
/**
 * Transforms a stream of message objects to NDJSON
 */
export default class MessageToNdjsonStream extends Transform {
    constructor();
    _transform(envelope: messages.Envelope, encoding: string, callback: TransformCallback): void;
}
//# sourceMappingURL=MessageToNdjsonStream.d.ts.map