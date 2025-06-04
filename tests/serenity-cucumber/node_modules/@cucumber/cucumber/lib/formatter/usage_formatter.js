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
const helpers_1 = require("./helpers");
const _1 = __importDefault(require("./"));
const cli_table3_1 = __importDefault(require("cli-table3"));
const value_checker_1 = require("../value_checker");
const messages = __importStar(require("@cucumber/messages"));
class UsageFormatter extends _1.default {
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
        if (usage.length === 0) {
            this.log('No step definitions');
            return;
        }
        const table = new cli_table3_1.default({
            head: ['Pattern / Text', 'Duration', 'Location'],
            style: {
                border: [],
                head: [],
            },
        });
        usage.forEach(({ line, matches, meanDuration, pattern, patternType, uri }) => {
            let formattedPattern = pattern;
            if (patternType === 'RegularExpression') {
                formattedPattern = '/' + formattedPattern + '/';
            }
            const col1 = [formattedPattern];
            const col2 = [];
            if (matches.length > 0) {
                if ((0, value_checker_1.doesHaveValue)(meanDuration)) {
                    col2.push(`${messages.TimeConversion.durationToMilliseconds(meanDuration).toFixed(2)}ms`);
                }
                else {
                    col2.push('-');
                }
            }
            else {
                col2.push('UNUSED');
            }
            const col3 = [(0, helpers_1.formatLocation)({ line, uri })];
            matches.slice(0, 5).forEach((match) => {
                col1.push(`  ${match.text}`);
                if ((0, value_checker_1.doesHaveValue)(match.duration)) {
                    col2.push(`${messages.TimeConversion.durationToMilliseconds(match.duration).toFixed(2)}ms`);
                }
                else {
                    col2.push('-');
                }
                col3.push((0, helpers_1.formatLocation)(match));
            });
            if (matches.length > 5) {
                col1.push(`  ${(matches.length - 5).toString()} more`);
            }
            table.push([col1.join('\n'), col2.join('\n'), col3.join('\n')]);
        });
        this.log(`${table.toString()}\n`);
    }
}
exports.default = UsageFormatter;
UsageFormatter.documentation = 'Prints where step definitions are used. The slowest step definitions (with duration) are listed first. If --dry-run is used the duration is not shown, and step definitions are sorted by filename instead.';
//# sourceMappingURL=usage_formatter.js.map