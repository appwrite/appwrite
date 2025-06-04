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
const value_checker_1 = require("../value_checker");
const messages = __importStar(require("@cucumber/messages"));
class SnippetsFormatter extends _1.default {
    constructor(options) {
        super(options);
        options.eventBroadcaster.on('envelope', (envelope) => {
            if ((0, value_checker_1.doesHaveValue)(envelope.testRunFinished)) {
                this.logSnippets();
            }
        });
    }
    logSnippets() {
        const snippets = [];
        this.eventDataCollector.getTestCaseAttempts().forEach((testCaseAttempt) => {
            const parsed = (0, helpers_1.parseTestCaseAttempt)({
                snippetBuilder: this.snippetBuilder,
                supportCodeLibrary: this.supportCodeLibrary,
                testCaseAttempt,
            });
            parsed.testSteps.forEach((testStep) => {
                if (testStep.result.status === messages.TestStepResultStatus.UNDEFINED) {
                    snippets.push(testStep.snippet);
                }
            });
        });
        this.log(snippets.join('\n\n'));
    }
}
exports.default = SnippetsFormatter;
SnippetsFormatter.documentation = "The Snippets Formatter doesn't output anything regarding the test run; it just prints snippets to implement any undefined steps";
//# sourceMappingURL=snippets_formatter.js.map