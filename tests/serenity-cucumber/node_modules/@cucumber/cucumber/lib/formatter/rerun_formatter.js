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
const gherkin_document_parser_1 = require("./helpers/gherkin_document_parser");
const value_checker_1 = require("../value_checker");
const messages = __importStar(require("@cucumber/messages"));
const DEFAULT_SEPARATOR = '\n';
function isFailedAttempt(worstTestStepResult) {
    return worstTestStepResult.status !== messages.TestStepResultStatus.PASSED;
}
class RerunFormatter extends _1.default {
    constructor(options) {
        super(options);
        options.eventBroadcaster.on('envelope', (envelope) => {
            if ((0, value_checker_1.doesHaveValue)(envelope.testRunFinished)) {
                this.logFailedTestCases();
            }
        });
        const rerunOptions = (0, value_checker_1.valueOrDefault)(options.parsedArgvOptions.rerun, {});
        this.separator = (0, value_checker_1.valueOrDefault)(rerunOptions.separator, DEFAULT_SEPARATOR);
    }
    getFailureMap() {
        const mapping = {};
        this.eventDataCollector
            .getTestCaseAttempts()
            .forEach(({ gherkinDocument, pickle, worstTestStepResult, willBeRetried }) => {
            if (isFailedAttempt(worstTestStepResult) && !willBeRetried) {
                const relativeUri = pickle.uri;
                const line = (0, gherkin_document_parser_1.getGherkinScenarioLocationMap)(gherkinDocument)[pickle.astNodeIds[pickle.astNodeIds.length - 1]].line;
                if ((0, value_checker_1.doesNotHaveValue)(mapping[relativeUri])) {
                    mapping[relativeUri] = [];
                }
                mapping[relativeUri].push(line);
            }
        });
        return mapping;
    }
    formatFailedTestCases() {
        const mapping = this.getFailureMap();
        return Object.keys(mapping)
            .map((uri) => {
            const lines = mapping[uri];
            return `${uri}:${lines.join(':')}`;
        })
            .join(this.separator);
    }
    logFailedTestCases() {
        const failedTestCases = this.formatFailedTestCases();
        this.log(failedTestCases);
    }
}
exports.default = RerunFormatter;
RerunFormatter.documentation = 'Prints failing files with line numbers.';
//# sourceMappingURL=rerun_formatter.js.map