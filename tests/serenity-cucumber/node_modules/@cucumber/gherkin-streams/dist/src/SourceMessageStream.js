"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const gherkin_1 = require("@cucumber/gherkin");
const path_1 = require("path");
const stream_1 = require("stream");
/**
 * Stream that reads a string and writes a single Source message.
 */
class SourceMessageStream extends stream_1.Transform {
    constructor(uri, relativeTo) {
        super({ readableObjectMode: true, writableObjectMode: false });
        this.uri = uri;
        this.relativeTo = relativeTo;
        this.buffer = Buffer.alloc(0);
    }
    _transform(chunk, encoding, callback) {
        this.buffer = Buffer.concat([this.buffer, chunk]);
        callback();
    }
    _flush(callback) {
        const data = this.buffer.toString('utf-8');
        const chunk = (0, gherkin_1.makeSourceEnvelope)(data, this.relativeTo ? (0, path_1.relative)(this.relativeTo, this.uri) : this.uri);
        this.push(chunk);
        callback();
    }
}
exports.default = SourceMessageStream;
//# sourceMappingURL=SourceMessageStream.js.map