"use strict";
var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const assert_1 = __importDefault(require("assert"));
const message_streams_1 = require("@cucumber/message-streams");
const stream_1 = require("stream");
const src_1 = require("../src");
const fs_1 = __importDefault(require("fs"));
const fast_glob_1 = __importDefault(require("fast-glob"));
const util_1 = require("util");
const asyncPipeline = (0, util_1.promisify)(stream_1.pipeline);
describe('Walking with messages', () => {
    const localMessageFiles = fast_glob_1.default.sync(`${__dirname}/messages/**/*.ndjson`);
    const tckMessageFiles = fast_glob_1.default.sync(`${__dirname}/../node_modules/@cucumber/compatibility-kit/features/**/*.ndjson`);
    const messageFiles = [].concat(localMessageFiles, tckMessageFiles);
    it('must have some messages for comparison', () => {
        assert_1.default.notEqual(messageFiles.length, 0);
    });
    for (const messageFile of messageFiles) {
        it(`can walk through GherkinDocuments in ${messageFile}`, () => __awaiter(void 0, void 0, void 0, function* () {
            const messageStream = new message_streams_1.NdjsonToMessageStream();
            yield asyncPipeline(fs_1.default.createReadStream(messageFile, 'utf-8'), messageStream, new stream_1.Writable({
                objectMode: true,
                write(envelope, _encoding, callback) {
                    try {
                        if (envelope.gherkinDocument) {
                            const walker = new src_1.GherkinDocumentWalker();
                            walker.walkGherkinDocument(envelope.gherkinDocument);
                        }
                        callback();
                    }
                    catch (error) {
                        error.message += `\n${envelope.gherkinDocument.uri}\n`;
                        callback(error);
                    }
                },
            }));
        })).timeout(30000);
    }
});
//# sourceMappingURL=messageTest.js.map