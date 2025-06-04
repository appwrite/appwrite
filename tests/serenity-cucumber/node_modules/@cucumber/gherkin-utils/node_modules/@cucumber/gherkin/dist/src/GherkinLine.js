"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const countSymbols_1 = __importDefault(require("./countSymbols"));
class GherkinLine {
    constructor(lineText, lineNumber) {
        this.lineText = lineText;
        this.lineNumber = lineNumber;
        this.trimmedLineText = lineText.replace(/^\s+/g, ''); // ltrim
        this.isEmpty = this.trimmedLineText.length === 0;
        this.indent = (0, countSymbols_1.default)(lineText) - (0, countSymbols_1.default)(this.trimmedLineText);
    }
    startsWith(prefix) {
        return this.trimmedLineText.indexOf(prefix) === 0;
    }
    startsWithTitleKeyword(keyword) {
        return this.startsWith(keyword + ':'); // The C# impl is more complicated. Find out why.
    }
    match(regexp) {
        return this.trimmedLineText.match(regexp);
    }
    getLineText(indentToRemove) {
        if (indentToRemove < 0 || indentToRemove > this.indent) {
            return this.trimmedLineText;
        }
        else {
            return this.lineText.substring(indentToRemove);
        }
    }
    getRestTrimmed(length) {
        return this.trimmedLineText.substring(length).trim();
    }
    getTableCells() {
        const cells = [];
        let col = 0;
        let startCol = col + 1;
        let cell = '';
        let firstCell = true;
        while (col < this.trimmedLineText.length) {
            let chr = this.trimmedLineText[col];
            col++;
            if (chr === '|') {
                if (firstCell) {
                    // First cell (content before the first |) is skipped
                    firstCell = false;
                }
                else {
                    // Keeps newlines
                    const trimmedLeft = cell.replace(/^[ \t\v\f\r\u0085\u00A0]*/g, '');
                    const trimmed = trimmedLeft.replace(/[ \t\v\f\r\u0085\u00A0]*$/g, '');
                    const cellIndent = cell.length - trimmedLeft.length;
                    const span = {
                        column: this.indent + startCol + cellIndent,
                        text: trimmed,
                    };
                    cells.push(span);
                }
                cell = '';
                startCol = col + 1;
            }
            else if (chr === '\\') {
                chr = this.trimmedLineText[col];
                col += 1;
                if (chr === 'n') {
                    cell += '\n';
                }
                else {
                    if (chr !== '|' && chr !== '\\') {
                        cell += '\\';
                    }
                    cell += chr;
                }
            }
            else {
                cell += chr;
            }
        }
        return cells;
    }
}
exports.default = GherkinLine;
module.exports = GherkinLine;
//# sourceMappingURL=GherkinLine.js.map