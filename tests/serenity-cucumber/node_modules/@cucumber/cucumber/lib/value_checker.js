"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.valueOrDefault = exports.doesNotHaveValue = exports.doesHaveValue = void 0;
function doesHaveValue(value) {
    return !doesNotHaveValue(value);
}
exports.doesHaveValue = doesHaveValue;
function doesNotHaveValue(value) {
    return value === null || value === undefined;
}
exports.doesNotHaveValue = doesNotHaveValue;
function valueOrDefault(value, defaultValue) {
    if (doesHaveValue(value)) {
        return value;
    }
    return defaultValue;
}
exports.valueOrDefault = valueOrDefault;
//# sourceMappingURL=value_checker.js.map