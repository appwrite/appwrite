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
import assert from 'assert';
import toArray from './toArray.js';
export default function verifyStreamContract(makeFromMessageStream, makeToMessageStream) {
    describe('contract', () => {
        it('can be serialised over a stream', () => __awaiter(this, void 0, void 0, function* () {
            const fromMessageStream = makeFromMessageStream();
            const toMessageStream = makeToMessageStream();
            fromMessageStream.pipe(toMessageStream);
            const outgoingMessages = [
                {
                    source: {
                        data: 'Feature: Hello',
                        uri: 'hello.feature',
                        mediaType: messages.SourceMediaType.TEXT_X_CUCUMBER_GHERKIN_PLAIN,
                    },
                },
                {
                    attachment: {
                        body: 'hello',
                        contentEncoding: messages.AttachmentContentEncoding.IDENTITY,
                        mediaType: 'text/plain',
                    },
                },
            ];
            for (const outgoingMessage of outgoingMessages) {
                fromMessageStream.write(outgoingMessage);
            }
            fromMessageStream.end();
            const incomingMessages = yield toArray(toMessageStream);
            assert.deepStrictEqual(JSON.parse(JSON.stringify(incomingMessages)), JSON.parse(JSON.stringify(outgoingMessages)));
        }));
    });
}
//# sourceMappingURL=verifyStreamContract.js.map