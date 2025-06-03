"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const helpers_1 = require("./helpers");
const _1 = __importDefault(require("./"));
const value_checker_1 = require("../value_checker");
class UsageJsonFormatter extends _1.default {
    constructor(options) {
        super(options);
        options.eventBroadcaster.on('envelope', (envelope) => {
            if ((0, value_checker_1.doesHaveValue)(envelope.testRunFinished)) {
                this.logUsage();
            }
        });
    }
    logUsage() {
        const usage = (0, helpers_1.getUsage)({
            stepDefinitions: this.supportCodeLibrary.stepDefinitions,
            eventDataCollector: this.eventDataCollector,
        });
        this.log(JSON.stringify(usage, this.replacer, 2));
    }
    replacer(key, value) {
        if (key === 'seconds') {
            return parseInt(value);
        }
        return value;
    }
}
exports.default = UsageJsonFormatter;
UsageJsonFormatter.documentation = 'Does what the Usage Formatter does, but outputs JSON, which can be output to a file and then consumed by other tools.';
//# sourceMappingURL=usage_json_formatter.js.map