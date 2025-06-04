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
const gherkin_1 = require("@cucumber/gherkin");
const assert_1 = __importDefault(require("assert"));
const src_1 = require("../src");
const defaultOptions = {};
describe('gherkin', () => {
    it('parses gherkin from the file system', () => __awaiter(void 0, void 0, void 0, function* () {
        const envelopes = yield streamToArray(src_1.GherkinStreams.fromPaths(['testdata/good/minimal.feature'], defaultOptions));
        assert_1.default.strictEqual(envelopes.length, 3);
        assert_1.default.strictEqual(envelopes[0].source.uri, 'testdata/good/minimal.feature');
        assert_1.default.strictEqual(envelopes[1].gherkinDocument.uri, 'testdata/good/minimal.feature');
        assert_1.default.strictEqual(envelopes[2].pickle.uri, 'testdata/good/minimal.feature');
    }));
    it('throws an error when the path is a directory', () => __awaiter(void 0, void 0, void 0, function* () {
        assert_1.default.rejects(() => __awaiter(void 0, void 0, void 0, function* () { return streamToArray(src_1.GherkinStreams.fromPaths(['testdata/good'], defaultOptions)); }));
    }));
    it('emits uris relative to a given path', () => __awaiter(void 0, void 0, void 0, function* () {
        const envelopes = yield streamToArray(src_1.GherkinStreams.fromPaths(['testdata/good/minimal.feature'], Object.assign(Object.assign({}, defaultOptions), { relativeTo: 'testdata/good' })));
        assert_1.default.strictEqual(envelopes.length, 3);
        assert_1.default.strictEqual(envelopes[0].source.uri, 'minimal.feature');
        assert_1.default.strictEqual(envelopes[1].gherkinDocument.uri, 'minimal.feature');
        assert_1.default.strictEqual(envelopes[2].pickle.uri, 'minimal.feature');
    }));
    it('parses gherkin from STDIN', () => __awaiter(void 0, void 0, void 0, function* () {
        const source = (0, gherkin_1.makeSourceEnvelope)(`Feature: Minimal

  Scenario: minimalistic
    Given the minimalism
`, 'test.feature');
        const envelopes = yield streamToArray(src_1.GherkinStreams.fromSources([source], defaultOptions));
        assert_1.default.strictEqual(envelopes.length, 3);
    }));
    it('parses gherkin using the provided default language', () => __awaiter(void 0, void 0, void 0, function* () {
        const source = (0, gherkin_1.makeSourceEnvelope)(`Fonctionnalité: i18n support
  Scénario: Support des caractères spéciaux
    Soit un exemple de scénario en français
`, 'test.feature');
        const envelopes = yield streamToArray(src_1.GherkinStreams.fromSources([source], { defaultDialect: 'fr' }));
        assert_1.default.strictEqual(envelopes.length, 3);
    }));
    it('outputs dialects', () => __awaiter(void 0, void 0, void 0, function* () {
        assert_1.default.strictEqual(gherkin_1.dialects.en.name, 'English');
    }));
});
function streamToArray(readableStream) {
    return __awaiter(this, void 0, void 0, function* () {
        return new Promise((resolve, reject) => {
            const items = [];
            readableStream.on('data', items.push.bind(items));
            readableStream.on('error', (err) => reject(err));
            readableStream.on('end', () => resolve(items));
        });
    });
}
//# sourceMappingURL=StreamTest.js.map