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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const _1 = __importDefault(require("./"));
const helpers_1 = require("./helpers");
const messages = __importStar(require("@cucumber/messages"));
const gherkin_document_parser_1 = require("./helpers/gherkin_document_parser");
const value_checker_1 = require("../value_checker");
const step_arguments_1 = require("../step_arguments");
const duration_helpers_1 = require("./helpers/duration_helpers");
const { getGherkinStepMap, getGherkinScenarioMap } = helpers_1.GherkinDocumentParser;
const { getScenarioDescription, getPickleStepMap, getStepKeyword } = helpers_1.PickleParser;
class JsonFormatter extends _1.default {
    constructor(options) {
        super(options);
        options.eventBroadcaster.on('envelope', (envelope) => {
            if ((0, value_checker_1.doesHaveValue)(envelope.testRunFinished)) {
                this.onTestRunFinished();
            }
        });
    }
    convertNameToId(obj) {
        return obj.name.replace(/ /g, '-').toLowerCase();
    }
    formatDataTable(dataTable) {
        return {
            rows: dataTable.rows.map((row) => ({
                cells: row.cells.map((x) => x.value),
            })),
        };
    }
    formatDocString(docString, gherkinStep) {
        return {
            content: docString.content,
            line: gherkinStep.docString.location.line,
        };
    }
    formatStepArgument(stepArgument, gherkinStep) {
        if ((0, value_checker_1.doesNotHaveValue)(stepArgument)) {
            return [];
        }
        return [
            (0, step_arguments_1.parseStepArgument)(stepArgument, {
                dataTable: (dataTable) => this.formatDataTable(dataTable),
                docString: (docString) => this.formatDocString(docString, gherkinStep),
            }),
        ];
    }
    onTestRunFinished() {
        const groupedTestCaseAttempts = {};
        this.eventDataCollector
            .getTestCaseAttempts()
            .forEach((testCaseAttempt) => {
            if (!testCaseAttempt.willBeRetried) {
                const uri = testCaseAttempt.pickle.uri;
                if ((0, value_checker_1.doesNotHaveValue)(groupedTestCaseAttempts[uri])) {
                    groupedTestCaseAttempts[uri] = [];
                }
                groupedTestCaseAttempts[uri].push(testCaseAttempt);
            }
        });
        const features = Object.keys(groupedTestCaseAttempts).map((uri) => {
            const group = groupedTestCaseAttempts[uri];
            const { gherkinDocument } = group[0];
            const gherkinStepMap = getGherkinStepMap(gherkinDocument);
            const gherkinScenarioMap = getGherkinScenarioMap(gherkinDocument);
            const gherkinExampleRuleMap = (0, gherkin_document_parser_1.getGherkinExampleRuleMap)(gherkinDocument);
            const gherkinScenarioLocationMap = (0, gherkin_document_parser_1.getGherkinScenarioLocationMap)(gherkinDocument);
            const elements = group.map((testCaseAttempt) => {
                const { pickle } = testCaseAttempt;
                const pickleStepMap = getPickleStepMap(pickle);
                let isBeforeHook = true;
                const steps = testCaseAttempt.testCase.testSteps.map((testStep) => {
                    isBeforeHook = isBeforeHook && !(0, value_checker_1.doesHaveValue)(testStep.pickleStepId);
                    return this.getStepData({
                        isBeforeHook,
                        gherkinStepMap,
                        pickleStepMap,
                        testStep,
                        testStepAttachments: testCaseAttempt.stepAttachments[testStep.id],
                        testStepResult: testCaseAttempt.stepResults[testStep.id],
                    });
                });
                return this.getScenarioData({
                    feature: gherkinDocument.feature,
                    gherkinScenarioLocationMap,
                    gherkinExampleRuleMap,
                    gherkinScenarioMap,
                    pickle,
                    steps,
                });
            });
            return this.getFeatureData({
                feature: gherkinDocument.feature,
                elements,
                uri,
            });
        });
        this.log(JSON.stringify(features, null, 2));
    }
    getFeatureData({ feature, elements, uri, }) {
        return {
            description: feature.description,
            elements,
            id: this.convertNameToId(feature),
            line: feature.location.line,
            keyword: feature.keyword,
            name: feature.name,
            tags: this.getFeatureTags(feature),
            uri,
        };
    }
    getScenarioData({ feature, gherkinScenarioLocationMap, gherkinExampleRuleMap, gherkinScenarioMap, pickle, steps, }) {
        const description = getScenarioDescription({
            pickle,
            gherkinScenarioMap,
        });
        return {
            description,
            id: this.formatScenarioId({ feature, pickle, gherkinExampleRuleMap }),
            keyword: gherkinScenarioMap[pickle.astNodeIds[0]].keyword,
            line: gherkinScenarioLocationMap[pickle.astNodeIds[pickle.astNodeIds.length - 1]].line,
            name: pickle.name,
            steps,
            tags: this.getScenarioTags({ feature, pickle, gherkinScenarioMap }),
            type: 'scenario',
        };
    }
    formatScenarioId({ feature, pickle, gherkinExampleRuleMap, }) {
        let parts;
        const rule = gherkinExampleRuleMap[pickle.astNodeIds[0]];
        if ((0, value_checker_1.doesHaveValue)(rule)) {
            parts = [feature, rule, pickle];
        }
        else {
            parts = [feature, pickle];
        }
        return parts.map((part) => this.convertNameToId(part)).join(';');
    }
    getStepData({ isBeforeHook, gherkinStepMap, pickleStepMap, testStep, testStepAttachments, testStepResult, }) {
        const data = {};
        if ((0, value_checker_1.doesHaveValue)(testStep.pickleStepId)) {
            const pickleStep = pickleStepMap[testStep.pickleStepId];
            data.arguments = this.formatStepArgument(pickleStep.argument, gherkinStepMap[pickleStep.astNodeIds[0]]);
            data.keyword = getStepKeyword({ pickleStep, gherkinStepMap });
            data.line = gherkinStepMap[pickleStep.astNodeIds[0]].location.line;
            data.name = pickleStep.text;
        }
        else {
            data.keyword = isBeforeHook ? 'Before' : 'After';
            data.hidden = true;
        }
        if ((0, value_checker_1.doesHaveValue)(testStep.stepDefinitionIds) &&
            testStep.stepDefinitionIds.length === 1) {
            const stepDefinition = this.supportCodeLibrary.stepDefinitions.find((s) => s.id === testStep.stepDefinitionIds[0]);
            data.match = { location: (0, helpers_1.formatLocation)(stepDefinition) };
        }
        const { message, status } = testStepResult;
        data.result = {
            status: messages.TestStepResultStatus[status].toLowerCase(),
        };
        if ((0, value_checker_1.doesHaveValue)(testStepResult.duration)) {
            data.result.duration = (0, duration_helpers_1.durationToNanoseconds)(testStepResult.duration);
        }
        if (status === messages.TestStepResultStatus.FAILED &&
            (0, value_checker_1.doesHaveValue)(message)) {
            data.result.error_message = message;
        }
        if ((testStepAttachments === null || testStepAttachments === void 0 ? void 0 : testStepAttachments.length) > 0) {
            data.embeddings = testStepAttachments.map((attachment) => ({
                data: attachment.body,
                mime_type: attachment.mediaType,
            }));
        }
        return data;
    }
    getFeatureTags(feature) {
        return feature.tags.map((tagData) => ({
            name: tagData.name,
            line: tagData.location.line,
        }));
    }
    getScenarioTags({ feature, pickle, gherkinScenarioMap, }) {
        const scenario = gherkinScenarioMap[pickle.astNodeIds[0]];
        return pickle.tags.map((tagData) => this.getScenarioTag(tagData, feature, scenario));
    }
    getScenarioTag(tagData, feature, scenario) {
        var _a;
        const byAstNodeId = (tag) => tag.id === tagData.astNodeId;
        const flatten = (acc, val) => acc.concat(val);
        const tag = feature.tags.find(byAstNodeId) ||
            scenario.tags.find(byAstNodeId) ||
            scenario.examples
                .map((e) => e.tags)
                .reduce(flatten, [])
                .find(byAstNodeId);
        return {
            name: tagData.name,
            line: (_a = tag === null || tag === void 0 ? void 0 : tag.location) === null || _a === void 0 ? void 0 : _a.line,
        };
    }
}
exports.default = JsonFormatter;
JsonFormatter.documentation = 'Prints the feature as JSON. The JSON format is in maintenance mode. Please consider using the message formatter with the standalone json-formatter (https://github.com/cucumber/json-formatter).';
//# sourceMappingURL=json_formatter.js.map