"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.formatError = void 0;
const assertion_error_formatter_1 = require("assertion-error-formatter");
const error_stack_parser_1 = __importDefault(require("error-stack-parser"));
const filter_stack_trace_1 = require("../filter_stack_trace");
function formatError(error, filterStackTraces) {
    let filteredStack;
    if (filterStackTraces) {
        try {
            filteredStack = (0, filter_stack_trace_1.filterStackTrace)(error_stack_parser_1.default.parse(error))
                .map((f) => f.source)
                .join('\n');
        }
        catch (_a) {
            // if we weren't able to parse and filter, we'll settle for the original
        }
    }
    const message = (0, assertion_error_formatter_1.format)(error, {
        colorFns: {
            errorStack: (stack) => filteredStack ? `\n${filteredStack}` : stack,
        },
    });
    return {
        message,
        exception: {
            type: error.name || 'Error',
            message: typeof error === 'string' ? error : error.message,
        },
    };
}
exports.formatError = formatError;
//# sourceMappingURL=format_error.js.map