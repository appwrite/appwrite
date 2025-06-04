import { parseEnvelope } from '@cucumber/messages';
import { Transform } from 'stream';
/**
 * Transforms an NDJSON stream to a stream of message objects
 */
export default class NdjsonToMessageStream extends Transform {
    /**
     * Create a new stream
     *
     * @param parseLine a function that parses a line. This function may ignore a line by returning null.
     */
    constructor(parseLine = parseEnvelope) {
        super({ writableObjectMode: false, readableObjectMode: true });
        this.parseLine = parseLine;
    }
    _transform(chunk, encoding, callback) {
        if (this.buffer === undefined) {
            this.buffer = '';
        }
        this.buffer += Buffer.isBuffer(chunk) ? chunk.toString('utf-8') : chunk;
        const lines = this.buffer.split('\n');
        if (!lines.length) {
            callback();
            return;
        }
        this.buffer = lines.pop();
        for (const line of lines) {
            if (line.trim().length > 0) {
                try {
                    const envelope = this.parseLine(line);
                    if (envelope !== null) {
                        this.push(envelope);
                    }
                }
                catch (err) {
                    err.message =
                        err.message +
                            `
Not JSON: '${line}'
`;
                    return callback(err);
                }
            }
        }
        callback();
    }
    _flush(callback) {
        if (this.buffer) {
            try {
                const object = JSON.parse(this.buffer);
                this.push(object);
            }
            catch (err) {
                return callback(new Error(`Not JSONs: ${this.buffer}`));
            }
        }
        callback();
    }
}
//# sourceMappingURL=NdjsonToMessageStream.js.map