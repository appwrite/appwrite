"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.parseStepArgument = void 0;
const util_1 = __importDefault(require("util"));
const value_checker_1 = require("./value_checker");
function parseStepArgument(arg, mapping) {
    if ((0, value_checker_1.doesHaveValue)(arg.dataTable)) {
        return mapping.dataTable(arg.dataTable);
    }
    else if ((0, value_checker_1.doesHaveValue)(arg.docString)) {
        return mapping.docString(arg.docString);
    }
    throw new Error(`Unknown step argument: ${util_1.default.inspect(arg)}`);
}
exports.parseStepArgument = parseStepArgument;
//# sourceMappingURL=step_arguments.js.map