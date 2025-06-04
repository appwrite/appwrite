"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.formatStepArgument = void 0;
const cli_table3_1 = __importDefault(require("cli-table3"));
const step_arguments_1 = require("../../step_arguments");
function formatDataTable(dataTable) {
    const table = new cli_table3_1.default({
        chars: {
            bottom: '',
            'bottom-left': '',
            'bottom-mid': '',
            'bottom-right': '',
            left: '|',
            'left-mid': '',
            mid: '',
            'mid-mid': '',
            middle: '|',
            right: '|',
            'right-mid': '',
            top: '',
            'top-left': '',
            'top-mid': '',
            'top-right': '',
        },
        style: {
            border: [],
            'padding-left': 1,
            'padding-right': 1,
        },
    });
    const rows = dataTable.rows.map((row) => row.cells.map((cell) => cell.value.replace(/\\/g, '\\\\').replace(/\n/g, '\\n')));
    table.push(...rows);
    return table.toString();
}
function formatDocString(docString) {
    return `"""\n${docString.content}\n"""`;
}
function formatStepArgument(arg) {
    return (0, step_arguments_1.parseStepArgument)(arg, {
        dataTable: formatDataTable,
        docString: formatDocString,
    });
}
exports.formatStepArgument = formatStepArgument;
//# sourceMappingURL=step_argument_formatter.js.map