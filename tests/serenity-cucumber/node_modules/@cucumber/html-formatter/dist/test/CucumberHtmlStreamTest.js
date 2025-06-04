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
const stream_1 = require("stream");
const CucumberHtmlStream_1 = __importDefault(require("../src/CucumberHtmlStream"));
function renderAsHtml(...envelopes) {
    return __awaiter(this, void 0, void 0, function* () {
        return new Promise((resolve, reject) => {
            let html = '';
            const sink = new stream_1.Writable({
                write(chunk, _, callback) {
                    html += chunk;
                    callback();
                },
            });
            sink.on('finish', () => resolve(html));
            const cucumberHtmlStream = new CucumberHtmlStream_1.default(`${__dirname}/dummy.css`, `${__dirname}/dummy.js`);
            cucumberHtmlStream.on('error', reject);
            cucumberHtmlStream.pipe(sink);
            for (const envelope of envelopes) {
                cucumberHtmlStream.write(envelope);
            }
            cucumberHtmlStream.end();
        });
    });
}
describe('CucumberHtmlStream', () => {
    it('writes zero messages to html', () => __awaiter(void 0, void 0, void 0, function* () {
        const html = yield renderAsHtml();
        (0, assert_1.default)(html.indexOf('window.CUCUMBER_MESSAGES = []') >= 0);
    }));
    it('writes one message to html', () => __awaiter(void 0, void 0, void 0, function* () {
        const e1 = {
            testRunStarted: {
                timestamp: { seconds: 0, nanos: 0 },
            },
        };
        const html = yield renderAsHtml(e1);
        (0, assert_1.default)(html.indexOf(`window.CUCUMBER_MESSAGES = [${JSON.stringify(e1)}]`) >= 0);
    }));
    it('writes two messages to html', () => __awaiter(void 0, void 0, void 0, function* () {
        const e1 = {
            testRunStarted: {
                timestamp: { seconds: 0, nanos: 0 },
            },
        };
        const e2 = {
            testRunFinished: {
                timestamp: { seconds: 0, nanos: 0 },
                success: true,
            },
        };
        const html = yield renderAsHtml(e1, e2);
        (0, assert_1.default)(html.indexOf(`window.CUCUMBER_MESSAGES = [${JSON.stringify(e1)},${JSON.stringify(e2)}]`) >= 0);
    }));
});
//# sourceMappingURL=CucumberHtmlStreamTest.js.map