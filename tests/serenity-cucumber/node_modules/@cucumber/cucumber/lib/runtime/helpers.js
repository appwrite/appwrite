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
exports.shouldCauseFailure = exports.retriesForPickle = exports.getAmbiguousStepException = void 0;
const location_helpers_1 = require("../formatter/helpers/location_helpers");
const cli_table3_1 = __importDefault(require("cli-table3"));
const indent_string_1 = __importDefault(require("indent-string"));
const pickle_filter_1 = require("../pickle_filter");
const messages = __importStar(require("@cucumber/messages"));
function getAmbiguousStepException(stepDefinitions) {
    const table = new cli_table3_1.default({
        chars: {
            bottom: '',
            'bottom-left': '',
            'bottom-mid': '',
            'bottom-right': '',
            left: '',
            'left-mid': '',
            mid: '',
            'mid-mid': '',
            middle: ' - ',
            right: '',
            'right-mid': '',
            top: '',
            'top-left': '',
            'top-mid': '',
            'top-right': '',
        },
        style: {
            border: [],
            'padding-left': 0,
            'padding-right': 0,
        },
    });
    table.push(...stepDefinitions.map((stepDefinition) => {
        const pattern = stepDefinition.pattern.toString();
        return [pattern, (0, location_helpers_1.formatLocation)(stepDefinition)];
    }));
    return `${'Multiple step definitions match:' + '\n'}${(0, indent_string_1.default)(table.toString(), 2)}`;
}
exports.getAmbiguousStepException = getAmbiguousStepException;
function retriesForPickle(pickle, options) {
    if (!options.retry) {
        return 0;
    }
    const retries = options.retry;
    if (retries === 0) {
        return 0;
    }
    const retryTagFilter = options.retryTagFilter;
    if (!retryTagFilter) {
        return retries;
    }
    const pickleTagFilter = new pickle_filter_1.PickleTagFilter(retryTagFilter);
    if (pickleTagFilter.matchesAllTagExpressions(pickle)) {
        return retries;
    }
    return 0;
}
exports.retriesForPickle = retriesForPickle;
function shouldCauseFailure(status, options) {
    if (options.dryRun) {
        return false;
    }
    const failureStatuses = [
        messages.TestStepResultStatus.AMBIGUOUS,
        messages.TestStepResultStatus.FAILED,
        messages.TestStepResultStatus.UNDEFINED,
    ];
    if (options.strict) {
        failureStatuses.push(messages.TestStepResultStatus.PENDING);
    }
    return failureStatuses.includes(status);
}
exports.shouldCauseFailure = shouldCauseFailure;
//# sourceMappingURL=helpers.js.map