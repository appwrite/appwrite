"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getStepKeywordType = exports.KeywordType = void 0;
const gherkin_1 = require("@cucumber/gherkin");
const value_checker_1 = require("../../value_checker");
var KeywordType;
(function (KeywordType) {
    KeywordType["Precondition"] = "precondition";
    KeywordType["Event"] = "event";
    KeywordType["Outcome"] = "outcome";
})(KeywordType = exports.KeywordType || (exports.KeywordType = {}));
function getStepKeywordType({ keyword, language, previousKeywordType, }) {
    const dialect = gherkin_1.dialects[language];
    const stepKeywords = ['given', 'when', 'then', 'and', 'but'];
    const type = stepKeywords.find((key) => dialect[key].includes(keyword));
    switch (type) {
        case 'when':
            return KeywordType.Event;
        case 'then':
            return KeywordType.Outcome;
        case 'and':
        case 'but':
            if ((0, value_checker_1.doesHaveValue)(previousKeywordType)) {
                return previousKeywordType;
            }
        // fallthrough
        default:
            return KeywordType.Precondition;
    }
}
exports.getStepKeywordType = getStepKeywordType;
//# sourceMappingURL=keyword_type.js.map