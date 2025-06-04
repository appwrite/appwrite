import { Transform } from 'stream';
/**
 * Transforms a stream of message objects to NDJSON
 */
export default class MessageToNdjsonStream extends Transform {
    constructor() {
        super({ writableObjectMode: true, readableObjectMode: false });
    }
    _transform(envelope, encoding, callback) {
        const json = JSON.stringify(envelope);
        this.push(json + '\n');
        callback();
    }
}
//# sourceMappingURL=MessageToNdjsonStream.js.map