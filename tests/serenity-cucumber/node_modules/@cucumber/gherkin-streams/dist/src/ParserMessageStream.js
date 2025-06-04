"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const gherkin_1 = require("@cucumber/gherkin");
const stream_1 = require("stream");
/**
 * Stream that reads Source messages and writes GherkinDocument and Pickle messages.
 */
class ParserMessageStream extends stream_1.Transform {
    constructor(options) {
        super({ writableObjectMode: true, readableObjectMode: true });
        this.options = options;
    }
    _transform(envelope, encoding, callback) {
        if (envelope.source) {
            const messageList = (0, gherkin_1.generateMessages)(envelope.source.data, envelope.source.uri, envelope.source.mediaType, this.options);
            for (const message of messageList) {
                this.push(message);
            }
        }
        callback();
    }
}
exports.default = ParserMessageStream;
//# sourceMappingURL=ParserMessageStream.js.map