"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const fs_1 = __importDefault(require("fs"));
const stream_1 = require("stream");
const makeGherkinOptions_1 = __importDefault(require("./makeGherkinOptions"));
const ParserMessageStream_1 = __importDefault(require("./ParserMessageStream"));
const SourceMessageStream_1 = __importDefault(require("./SourceMessageStream"));
function fromPaths(paths, options) {
    const pathsCopy = paths.slice();
    options = (0, makeGherkinOptions_1.default)(options);
    const combinedMessageStream = new stream_1.PassThrough({
        writableObjectMode: true,
        readableObjectMode: true,
    });
    function pipeSequentially() {
        const path = pathsCopy.shift();
        if (path !== undefined) {
            const parserMessageStream = new ParserMessageStream_1.default(options);
            parserMessageStream.on('end', () => {
                pipeSequentially();
            });
            const end = pathsCopy.length === 0;
            // Can't use pipeline here because of the { end } argument,
            // so we have to manually propagate errors.
            fs_1.default.createReadStream(path, { encoding: 'utf-8' })
                .on('error', (err) => combinedMessageStream.emit('error', err))
                .pipe(new SourceMessageStream_1.default(path, options.relativeTo))
                .on('error', (err) => combinedMessageStream.emit('error', err))
                .pipe(parserMessageStream)
                .on('error', (err) => combinedMessageStream.emit('error', err))
                .pipe(combinedMessageStream, { end });
        }
    }
    pipeSequentially();
    return combinedMessageStream;
}
function fromSources(envelopes, options) {
    const envelopesCopy = envelopes.slice();
    options = (0, makeGherkinOptions_1.default)(options);
    const combinedMessageStream = new stream_1.PassThrough({
        writableObjectMode: true,
        readableObjectMode: true,
    });
    function pipeSequentially() {
        const envelope = envelopesCopy.shift();
        if (envelope !== undefined && envelope.source) {
            const parserMessageStream = new ParserMessageStream_1.default(options);
            parserMessageStream.pipe(combinedMessageStream, {
                end: envelopesCopy.length === 0,
            });
            parserMessageStream.on('end', pipeSequentially);
            parserMessageStream.end(envelope);
        }
    }
    pipeSequentially();
    return combinedMessageStream;
}
exports.default = {
    fromPaths,
    fromSources,
};
//# sourceMappingURL=GherkinStreams.js.map