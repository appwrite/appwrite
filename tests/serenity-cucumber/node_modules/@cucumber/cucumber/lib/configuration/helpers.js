"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.isTruthyString = void 0;
function isTruthyString(s) {
    if (s === undefined) {
        return false;
    }
    return s.match(/^(false|no|0)$/i) === null;
}
exports.isTruthyString = isTruthyString;
//# sourceMappingURL=helpers.js.map