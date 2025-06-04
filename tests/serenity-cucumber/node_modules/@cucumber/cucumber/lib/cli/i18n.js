"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.getKeywords = exports.getLanguages = void 0;
const gherkin_1 = require("@cucumber/gherkin");
const cli_table3_1 = __importDefault(require("cli-table3"));
const capital_case_1 = require("capital-case");
const keywords = [
    'feature',
    'rule',
    'background',
    'scenario',
    'scenarioOutline',
    'examples',
    'given',
    'when',
    'then',
    'and',
    'but',
];
function getAsTable(header, rows) {
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
            middle: ' | ',
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
    table.push(header);
    table.push(...rows);
    return table.toString();
}
function getLanguages() {
    const rows = Object.keys(gherkin_1.dialects).map((isoCode) => [
        isoCode,
        gherkin_1.dialects[isoCode].name,
        gherkin_1.dialects[isoCode].native,
    ]);
    return getAsTable(['ISO 639-1', 'ENGLISH NAME', 'NATIVE NAME'], rows);
}
exports.getLanguages = getLanguages;
function getKeywords(isoCode) {
    const language = gherkin_1.dialects[isoCode];
    const rows = keywords.map((keyword) => {
        const words = language[keyword].map((s) => `"${s}"`).join(', ');
        return [(0, capital_case_1.capitalCase)(keyword), words];
    });
    return getAsTable(['ENGLISH KEYWORD', 'NATIVE KEYWORDS'], rows);
}
exports.getKeywords = getKeywords;
//# sourceMappingURL=i18n.js.map