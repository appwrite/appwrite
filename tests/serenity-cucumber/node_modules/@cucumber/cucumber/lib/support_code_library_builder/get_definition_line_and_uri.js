"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.getDefinitionLineAndUri = void 0;
const path_1 = __importDefault(require("path"));
const error_stack_parser_1 = __importDefault(require("error-stack-parser"));
const filter_stack_trace_1 = require("../filter_stack_trace");
const value_checker_1 = require("../value_checker");
function getDefinitionLineAndUri(cwd, isExcluded = filter_stack_trace_1.isFileNameInCucumber) {
    let line;
    let uri;
    const stackframes = error_stack_parser_1.default.parse(new Error());
    const stackframe = stackframes.find((frame) => frame.fileName !== __filename && !isExcluded(frame.fileName));
    if (stackframe != null) {
        line = stackframe.getLineNumber();
        uri = stackframe.getFileName();
        if ((0, value_checker_1.doesHaveValue)(uri)) {
            uri = path_1.default.relative(cwd, uri);
        }
    }
    return {
        line: (0, value_checker_1.valueOrDefault)(line, 0),
        uri: (0, value_checker_1.valueOrDefault)(uri, 'unknown'),
    };
}
exports.getDefinitionLineAndUri = getDefinitionLineAndUri;
//# sourceMappingURL=get_definition_line_and_uri.js.map