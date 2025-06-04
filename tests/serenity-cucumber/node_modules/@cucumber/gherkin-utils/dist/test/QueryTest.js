"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
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
const gherkin_streams_1 = require("@cucumber/gherkin-streams");
const messages = __importStar(require("@cucumber/messages"));
const stream_1 = require("stream");
const assert_1 = __importDefault(require("assert"));
const Query_1 = __importDefault(require("../src/Query"));
const util_1 = require("util");
const pipelinePromise = (0, util_1.promisify)(stream_1.pipeline);
describe('Query', () => {
    let gherkinQuery;
    let envelopes;
    beforeEach(() => {
        envelopes = [];
        gherkinQuery = new Query_1.default();
    });
    describe('#getLocation(astNodeId)', () => {
        it('looks up a scenario line number', () => __awaiter(void 0, void 0, void 0, function* () {
            yield parse(`Feature: hello
  Scenario: hi
    Given a passed step
`);
            const pickle = envelopes.find((e) => e.pickle).pickle;
            const gherkinScenarioId = pickle.astNodeIds[0];
            const location = gherkinQuery.getLocation(gherkinScenarioId);
            assert_1.default.deepStrictEqual(location.line, 2);
        }));
        it('looks up a step line number', () => __awaiter(void 0, void 0, void 0, function* () {
            yield parse(`Feature: hello
  Scenario: hi
    Given a passed step
`);
            const pickleStep = envelopes.find((e) => e.pickle).pickle.steps[0];
            const gherkinStepId = pickleStep.astNodeIds[0];
            const location = gherkinQuery.getLocation(gherkinStepId);
            assert_1.default.deepStrictEqual(location.line, 3);
        }));
    });
    describe('#getPickleIds(uri, astNodeId)', () => {
        it('looks up pickle IDs for a scenario', () => __awaiter(void 0, void 0, void 0, function* () {
            yield parse(`Feature: hello
  Background:
    Given a background step

  Scenario: hi
    Given a passed step
`);
            const gherkinDocument = envelopes.find((envelope) => envelope.gherkinDocument).gherkinDocument;
            const scenario = gherkinDocument.feature.children.find((child) => child.scenario).scenario;
            const pickleId = envelopes.find((e) => e.pickle).pickle.id;
            const pickleIds = gherkinQuery.getPickleIds('test.feature', scenario.id);
            assert_1.default.deepStrictEqual(pickleIds, [pickleId]);
        }));
        it('looks up pickle IDs for a whole document', () => __awaiter(void 0, void 0, void 0, function* () {
            yield parse(`Feature: hello
  Scenario:
    Given a failed step

  Scenario: hi
    Given a passed step
`);
            const expectedPickleIds = envelopes.filter((e) => e.pickle).map((e) => e.pickle.id);
            const pickleIds = gherkinQuery.getPickleIds('test.feature');
            assert_1.default.deepStrictEqual(pickleIds, expectedPickleIds);
        }));
        it.skip('fails to look up pickle IDs for a step', () => __awaiter(void 0, void 0, void 0, function* () {
            yield parse(`Feature: hello
  Background:
    Given a background step

  Scenario: hi
    Given a passed step
`);
            assert_1.default.throws(() => gherkinQuery.getPickleIds('test.feature', 'some-non-existing-id'), {
                message: 'No values found for key 6. Keys: [some-non-existing-id]',
            });
        }));
        it('avoids dupes and ignores empty scenarios', () => __awaiter(void 0, void 0, void 0, function* () {
            yield parse(`Feature: Examples and empty scenario

  Scenario: minimalistic
    Given the <what>

    Examples:
      | what |
      | foo  |

    Examples:
      | what |
      | bar  |

  Scenario: ha ok
`);
            const pickleIds = gherkinQuery.getPickleIds('test.feature');
            // One for each table, and one for the empty scenario
            // https://github.com/cucumber/cucumber/issues/249
            assert_1.default.strictEqual(pickleIds.length, 3, pickleIds.join(','));
        }));
    });
    describe('#getPickleStepIds(astNodeId', () => {
        it('returns an empty list when the ID is unknown', () => __awaiter(void 0, void 0, void 0, function* () {
            yield parse('Feature: An empty feature');
            assert_1.default.deepEqual(gherkinQuery.getPickleStepIds('whatever-id'), []);
        }));
        it('returns the pickle step IDs corresponding the a scenario step', () => __awaiter(void 0, void 0, void 0, function* () {
            yield parse(`Feature: hello
  Scenario:
    Given a failed step
`);
            const pickleStepIds = envelopes
                .find((envelope) => envelope.pickle)
                .pickle.steps.map((pickleStep) => pickleStep.id);
            const stepId = envelopes.find((envelope) => envelope.gherkinDocument).gherkinDocument.feature
                .children[0].scenario.steps[0].id;
            assert_1.default.deepEqual(gherkinQuery.getPickleStepIds(stepId), pickleStepIds);
        }));
        context('when a step has multiple pickle step', () => {
            it('returns all pickleStepIds linked to a background step', () => __awaiter(void 0, void 0, void 0, function* () {
                yield parse(`Feature: hello
  Background:
    Given a step that will have 2 pickle steps

  Scenario:
    Given a step that will only have 1 pickle step

    Scenario:
    Given a step that will only have 1 pickle step
  `);
                const backgroundStepId = envelopes.find((envelope) => envelope.gherkinDocument)
                    .gherkinDocument.feature.children[0].background.steps[0].id;
                const pickleStepIds = envelopes
                    .filter((envelope) => envelope.pickle)
                    .map((envelope) => envelope.pickle.steps[0].id);
                assert_1.default.deepEqual(gherkinQuery.getPickleStepIds(backgroundStepId), pickleStepIds);
            }));
            it('return all pickleStepIds linked to a step in a scenario with examples', () => __awaiter(void 0, void 0, void 0, function* () {
                yield parse(`Feature: hello
  Scenario:
    Given a passed step
    And a <status> step

    Examples:
      | status |
      | passed |
      | failed |
`);
                const scenarioStepId = envelopes.find((envelope) => envelope.gherkinDocument)
                    .gherkinDocument.feature.children[0].scenario.steps[1].id;
                const pickleStepIds = envelopes
                    .filter((envelope) => envelope.pickle)
                    .map((envelope) => envelope.pickle.steps[1].id);
                assert_1.default.deepEqual(gherkinQuery.getPickleStepIds(scenarioStepId), pickleStepIds);
            }));
        });
    });
    function parse(gherkinSource) {
        const writable = new stream_1.Writable({
            objectMode: true,
            write(envelope, encoding, callback) {
                envelopes.push(envelope);
                try {
                    gherkinQuery.update(envelope);
                    callback();
                }
                catch (err) {
                    callback(err);
                }
            },
        });
        return pipelinePromise(gherkinMessages(gherkinSource, 'test.feature'), writable);
    }
    function gherkinMessages(gherkinSource, uri) {
        const source = {
            source: {
                uri,
                data: gherkinSource,
                mediaType: messages.SourceMediaType.TEXT_X_CUCUMBER_GHERKIN_PLAIN,
            },
        };
        const newId = messages.IdGenerator.incrementing();
        return gherkin_streams_1.GherkinStreams.fromSources([source], { newId });
    }
});
//# sourceMappingURL=QueryTest.js.map