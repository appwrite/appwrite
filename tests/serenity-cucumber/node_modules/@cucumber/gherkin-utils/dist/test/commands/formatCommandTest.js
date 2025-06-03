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
const formatCommand_1 = require("../../src/commands/formatCommand");
const assert_1 = __importDefault(require("assert"));
const fs_1 = require("fs");
const os_1 = __importDefault(require("os"));
const util_1 = require("util");
const stream_1 = require("stream");
const mkdtemp = (0, util_1.promisify)(fs_1.mkdtemp);
const mkdir = (0, util_1.promisify)(fs_1.mkdir);
const writeFile = (0, util_1.promisify)(fs_1.writeFile);
const readFile = (0, util_1.promisify)(fs_1.readFile);
class BufStream extends stream_1.Writable {
    constructor() {
        super(...arguments);
        this.buf = Buffer.alloc(0);
    }
    _write(chunk, encoding, callback) {
        this.buf = Buffer.concat([this.buf, chunk]);
        callback();
    }
}
describe('formatCommand', () => {
    let tmpdir;
    beforeEach(() => __awaiter(void 0, void 0, void 0, function* () {
        tmpdir = yield mkdtemp(os_1.default.tmpdir() + '/');
    }));
    it('formats STDIN Gherkin to STDOUT Markdown', () => __awaiter(void 0, void 0, void 0, function* () {
        const stdin = stream_1.Readable.from(Buffer.from('Feature: Hello\n'));
        const stdout = new BufStream();
        yield (0, formatCommand_1.formatCommand)([], stdin, stdout, { fromSyntax: 'gherkin', toSyntax: 'markdown' });
        assert_1.default.deepStrictEqual(stdout.buf.toString('utf-8'), '# Feature: Hello\n');
    }));
    it('formats STDIN Markdown to STDOUT Gherkin', () => __awaiter(void 0, void 0, void 0, function* () {
        const stdin = stream_1.Readable.from(Buffer.from('# Feature: Hello\n'));
        const stdout = new BufStream();
        yield (0, formatCommand_1.formatCommand)([], stdin, stdout, { fromSyntax: 'markdown', toSyntax: 'gherkin' });
        assert_1.default.deepStrictEqual(stdout.buf.toString('utf-8'), 'Feature: Hello\n');
    }));
    it('formats Gherkin file in-place', () => __awaiter(void 0, void 0, void 0, function* () {
        const path = `${tmpdir}/source.feature`;
        yield writeFile(path, '   Feature: Hello\n', 'utf-8');
        yield (0, formatCommand_1.formatCommand)([path], null, null, {});
        const gherkin = yield readFile(path, 'utf-8');
        assert_1.default.deepStrictEqual(gherkin, 'Feature: Hello\n');
    }));
    it('formats Markdown file in-place', () => __awaiter(void 0, void 0, void 0, function* () {
        const path = `${tmpdir}/source.feature.md`;
        yield writeFile(path, '# Feature: Hello\n', 'utf-8');
        yield (0, formatCommand_1.formatCommand)([path], null, null, {});
        const markdown = yield readFile(path, 'utf-8');
        assert_1.default.deepStrictEqual(markdown, '# Feature: Hello\n');
    }));
    it('formats/moves Gherkin file to Markdown file', () => __awaiter(void 0, void 0, void 0, function* () {
        const fromPath = `${tmpdir}/source.feature`;
        yield writeFile(fromPath, 'Feature: Hello\n', 'utf-8');
        const toPath = `${tmpdir}/source.feature.md`;
        yield (0, formatCommand_1.formatCommand)([fromPath], null, null, { toSyntax: 'markdown' });
        const markdown = yield readFile(toPath, 'utf-8');
        assert_1.default.deepStrictEqual(markdown, '# Feature: Hello\n');
        (0, assert_1.default)(!(0, fs_1.existsSync)(fromPath));
    }));
    it('formats/moves Markdown file to Gherkin file', () => __awaiter(void 0, void 0, void 0, function* () {
        const fromPath = `${tmpdir}/source.feature.md`;
        yield writeFile(fromPath, '# Feature: Hello\n', 'utf-8');
        const toPath = `${tmpdir}/source.feature`;
        yield (0, formatCommand_1.formatCommand)([fromPath], null, null, { toSyntax: 'gherkin' });
        const markdown = yield readFile(toPath, 'utf-8');
        assert_1.default.deepStrictEqual(markdown, 'Feature: Hello\n');
        (0, assert_1.default)(!(0, fs_1.existsSync)(fromPath));
    }));
    it('throws an error when fromSyntax inconsitent with file extension', () => __awaiter(void 0, void 0, void 0, function* () {
        const fromPath = `${tmpdir}/source.feature.md`;
        yield assert_1.default.rejects((0, formatCommand_1.formatCommand)([fromPath], null, null, { fromSyntax: 'gherkin' }));
    }));
});
//# sourceMappingURL=formatCommandTest.js.map