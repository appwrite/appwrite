"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var assert_1 = __importDefault(require("assert"));
var index_js_1 = require("../src/index.js");
describe('messages', function () {
    it('defaults missing fields when deserialising from JSON', function () {
        // Sample envelope from before we moved from protobuf to JSON Schema
        var partialGherkinDocumentEnvelope = {
            gherkinDocument: {
                feature: {
                    children: [
                        {
                            scenario: {
                                id: '1',
                                keyword: 'Scenario',
                                location: { column: 3, line: 3 },
                                name: 'minimalistic',
                                steps: [
                                    {
                                        id: '0',
                                        keyword: 'Given ',
                                        keywordType: index_js_1.StepKeywordType.CONTEXT,
                                        location: { column: 5, line: 4 },
                                        text: 'the minimalism',
                                    },
                                ],
                            },
                        },
                    ],
                    keyword: 'Feature',
                    language: 'en',
                    location: { column: 1, line: 1 },
                    name: 'Minimal',
                },
                uri: 'testdata/good/minimal.feature',
            },
        };
        var envelope = (0, index_js_1.parseEnvelope)(JSON.stringify(partialGherkinDocumentEnvelope));
        var expectedEnvelope = {
            gherkinDocument: {
                // new
                comments: [],
                feature: {
                    // new
                    tags: [],
                    // new
                    description: '',
                    children: [
                        {
                            scenario: {
                                // new
                                examples: [],
                                // new
                                description: '',
                                // new
                                tags: [],
                                id: '1',
                                keyword: 'Scenario',
                                location: { column: 3, line: 3 },
                                name: 'minimalistic',
                                steps: [
                                    {
                                        id: '0',
                                        keyword: 'Given ',
                                        keywordType: index_js_1.StepKeywordType.CONTEXT,
                                        location: { column: 5, line: 4 },
                                        text: 'the minimalism',
                                    },
                                ],
                            },
                        },
                    ],
                    keyword: 'Feature',
                    language: 'en',
                    location: { column: 1, line: 1 },
                    name: 'Minimal',
                },
                uri: 'testdata/good/minimal.feature',
            },
        };
        assert_1.default.deepStrictEqual(JSON.parse(JSON.stringify(envelope)), JSON.parse(JSON.stringify(expectedEnvelope)));
    });
});
//# sourceMappingURL=messagesTest.js.map