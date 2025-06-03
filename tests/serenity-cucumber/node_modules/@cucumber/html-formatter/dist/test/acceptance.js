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
const message_streams_1 = require("@cucumber/message-streams");
const assert_1 = __importDefault(require("assert"));
const fs_1 = __importDefault(require("fs"));
const glob_1 = require("glob");
const path_1 = __importDefault(require("path"));
const puppeteer_1 = __importDefault(require("puppeteer"));
const stream_1 = require("stream");
const CucumberHtmlStream_1 = __importDefault(require("../src/CucumberHtmlStream"));
function canRenderHtml(html) {
    return __awaiter(this, void 0, void 0, function* () {
        const browser = yield puppeteer_1.default.launch({
            headless: true,
            args: ['--no-sandbox'],
        });
        const page = yield browser.newPage();
        yield page.setContent(html);
        const dynamicHTML = yield page.evaluate(() => {
            const content = document.querySelector('[data-testid="cucumber-react"]');
            return content && content.innerHTML;
        });
        yield browser.close();
        if (!dynamicHTML) {
            return false;
        }
        return true;
    });
}
describe('html-formatter', () => {
    const files = (0, glob_1.sync)(`./node_modules/@cucumber/compatibility-kit/features/**/*.ndjson`);
    for (const ndjson of files) {
        it(`can render ${path_1.default.basename(ndjson, '.ndjson')}`, () => __awaiter(void 0, void 0, void 0, function* () {
            const ndjsonData = fs_1.default.createReadStream(ndjson, { encoding: 'utf-8' });
            const toMessageStream = new message_streams_1.NdjsonToMessageStream();
            const htmlData = yield new Promise((resolve, reject) => {
                const chunks = [];
                const out = new stream_1.PassThrough()
                    .on('data', (chunk) => chunks.push(Buffer.from(chunk)))
                    .on('end', () => resolve(Buffer.concat(chunks).toString()));
                (0, stream_1.pipeline)(ndjsonData, toMessageStream, new CucumberHtmlStream_1.default(__dirname + '/../dist/main.css', __dirname + '/../dist/main.js'), out, (err) => {
                    if (err) {
                        reject(err);
                    }
                });
            });
            assert_1.default.ok(yield canRenderHtml(htmlData.toString()));
        }));
    }
});
//# sourceMappingURL=acceptance.js.map