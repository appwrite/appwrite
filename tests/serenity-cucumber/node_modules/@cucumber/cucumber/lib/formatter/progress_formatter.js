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
const summary_formatter_1 = __importDefault(require("./summary_formatter"));
const value_checker_1 = require("../value_checker");
const messages = __importStar(require("@cucumber/messages"));
const STATUS_CHARACTER_MAPPING = new Map([
    [messages.TestStepResultStatus.AMBIGUOUS, 'A'],
    [messages.TestStepResultStatus.FAILED, 'F'],
    [messages.TestStepResultStatus.PASSED, '.'],
    [messages.TestStepResultStatus.PENDING, 'P'],
    [messages.TestStepResultStatus.SKIPPED, '-'],
    [messages.TestStepResultStatus.UNDEFINED, 'U'],
]);
class ProgressFormatter extends summary_formatter_1.default {
    constructor(options) {
        options.eventBroadcaster.on('envelope', (envelope) => {
            if ((0, value_checker_1.doesHaveValue)(envelope.testRunFinished)) {
                this.log('\n\n');
            }
            else if ((0, value_checker_1.doesHaveValue)(envelope.testStepFinished)) {
                this.logProgress(envelope.testStepFinished);
            }
        });
        super(options);
    }
    logProgress({ testStepResult: { status } }) {
        const character = this.colorFns.forStatus(status)(STATUS_CHARACTER_MAPPING.get(status));
        this.log(character);
    }
}
exports.default = ProgressFormatter;
ProgressFormatter.documentation = 'Prints one character per scenario.';
//# sourceMappingURL=progress_formatter.js.map