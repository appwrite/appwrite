var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
import * as messages from '@cucumber/messages';
import { Envelope } from '@cucumber/messages';
import assert from 'assert';
import { MessageToNdjsonStream } from '../src/index.js';
import NdjsonToMessageStream from '../src/NdjsonToMessageStream.js';
import toArray from './toArray.js';
import verifyStreamContract from './verifyStreamContract.js';
describe('NdjsonStream', () => {
    const makeToMessageStream = () => new NdjsonToMessageStream();
    const makeFromMessageStream = () => new MessageToNdjsonStream();
    verifyStreamContract(makeFromMessageStream, makeToMessageStream);
    it('converts a buffer stream written byte by byte', (cb) => {
        const stream = makeToMessageStream();
        const envelope = {
            testStepFinished: {
                testStepResult: {
                    status: messages.TestStepResultStatus.UNKNOWN,
                    duration: { nanos: 0, seconds: 0 },
                },
                testCaseStartedId: '1',
                testStepId: '2',
                timestamp: {
                    seconds: 0,
                    nanos: 0,
                },
            },
        };
        const json = JSON.stringify(envelope);
        stream.on('error', cb);
        stream.on('data', (receivedEnvelope) => {
            assert.deepStrictEqual(envelope, receivedEnvelope);
            cb();
        });
        const buffer = Buffer.from(json);
        for (let i = 0; i < buffer.length; i++) {
            stream.write(buffer.slice(i, i + 1));
        }
        stream.end();
    });
    it('converts messages to JSON with enums as strings', (cb) => {
        const stream = new MessageToNdjsonStream();
        stream.on('data', (json) => {
            const ob = JSON.parse(json);
            const expected = {
                testStepFinished: {
                    testStepResult: {
                        status: messages.TestStepResultStatus.UNKNOWN,
                        duration: { nanos: 0, seconds: 0 },
                    },
                    testCaseStartedId: '1',
                    testStepId: '2',
                    timestamp: {
                        seconds: 0,
                        nanos: 0,
                    },
                },
            };
            assert.deepStrictEqual(ob, expected);
            cb();
        });
        const envelope = {
            testStepFinished: {
                testStepResult: {
                    status: messages.TestStepResultStatus.UNKNOWN,
                    duration: { nanos: 0, seconds: 0 },
                },
                testCaseStartedId: '1',
                testStepId: '2',
                timestamp: {
                    seconds: 0,
                    nanos: 0,
                },
            },
        };
        stream.write(envelope);
    });
    it('ignores empty lines', () => __awaiter(void 0, void 0, void 0, function* () {
        const toMessageStream = makeToMessageStream();
        toMessageStream.write('{}\n{}\n\n{}\n');
        toMessageStream.end();
        const incomingMessages = yield toArray(toMessageStream);
        assert.deepStrictEqual(incomingMessages, [
            new Envelope(),
            new Envelope(),
            new Envelope(),
        ]);
    }));
    it('includes offending line in error message', () => __awaiter(void 0, void 0, void 0, function* () {
        const toMessageStream = makeToMessageStream();
        yield assert.rejects(() => __awaiter(void 0, void 0, void 0, function* () {
            toMessageStream.write('{}\n  BLA BLA\n\n{}\n');
            toMessageStream.end();
            yield toArray(toMessageStream);
        }), {
            message: "Unexpected token B in JSON at position 2\nNot JSON: '  BLA BLA'\n",
        });
    }));
});
//# sourceMappingURL=NdjsonStreamTest.js.map