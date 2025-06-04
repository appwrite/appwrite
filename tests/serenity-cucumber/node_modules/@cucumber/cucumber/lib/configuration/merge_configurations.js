"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.mergeConfigurations = void 0;
const lodash_mergewith_1 = __importDefault(require("lodash.mergewith"));
const ADDITIVE_ARRAYS = [
    'format',
    'import',
    'name',
    'paths',
    'require',
    'requireModule',
];
const TAG_EXPRESSIONS = ['tags', 'retryTagFilter'];
function mergeArrays(objValue, srcValue) {
    if (objValue && srcValue) {
        return [].concat(objValue, srcValue);
    }
    return undefined;
}
function mergeTagExpressions(objValue, srcValue) {
    if (objValue && srcValue) {
        return `${wrapTagExpression(objValue)} and ${wrapTagExpression(srcValue)}`;
    }
    return undefined;
}
function wrapTagExpression(raw) {
    if (raw.startsWith('(') && raw.endsWith(')')) {
        return raw;
    }
    return `(${raw})`;
}
function customizer(objValue, srcValue, key) {
    if (ADDITIVE_ARRAYS.includes(key)) {
        return mergeArrays(objValue, srcValue);
    }
    if (TAG_EXPRESSIONS.includes(key)) {
        return mergeTagExpressions(objValue, srcValue);
    }
    return undefined;
}
function mergeConfigurations(source, ...configurations) {
    return (0, lodash_mergewith_1.default)({}, source, ...configurations, customizer);
}
exports.mergeConfigurations = mergeConfigurations;
//# sourceMappingURL=merge_configurations.js.map