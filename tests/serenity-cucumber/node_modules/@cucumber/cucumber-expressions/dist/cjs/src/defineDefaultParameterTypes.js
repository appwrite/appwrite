"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var ParameterType_js_1 = __importDefault(require("./ParameterType.js"));
var INTEGER_REGEXPS = [/-?\d+/, /\d+/];
var FLOAT_REGEXP = /(?=.*\d.*)[-+]?\d*(?:\.(?=\d.*))?\d*(?:\d+[E][+-]?\d+)?/;
var WORD_REGEXP = /[^\s]+/;
var STRING_REGEXP = /"([^"\\]*(\\.[^"\\]*)*)"|'([^'\\]*(\\.[^'\\]*)*)'/;
var ANONYMOUS_REGEXP = /.*/;
function defineDefaultParameterTypes(registry) {
    registry.defineParameterType(new ParameterType_js_1.default('int', INTEGER_REGEXPS, Number, function (s) { return (s === undefined ? null : Number(s)); }, true, true, true));
    registry.defineParameterType(new ParameterType_js_1.default('float', FLOAT_REGEXP, Number, function (s) { return (s === undefined ? null : parseFloat(s)); }, true, false, true));
    registry.defineParameterType(new ParameterType_js_1.default('word', WORD_REGEXP, String, function (s) { return s; }, false, false, true));
    registry.defineParameterType(new ParameterType_js_1.default('string', STRING_REGEXP, String, function (s1, s2) { return (s1 || s2 || '').replace(/\\"/g, '"').replace(/\\'/g, "'"); }, true, false, true));
    registry.defineParameterType(new ParameterType_js_1.default('', ANONYMOUS_REGEXP, String, function (s) { return s; }, false, true, true));
    registry.defineParameterType(new ParameterType_js_1.default('double', FLOAT_REGEXP, Number, function (s) { return (s === undefined ? null : parseFloat(s)); }, false, false, true));
    registry.defineParameterType(new ParameterType_js_1.default('bigdecimal', FLOAT_REGEXP, String, function (s) { return (s === undefined ? null : s); }, false, false, true));
    registry.defineParameterType(new ParameterType_js_1.default('byte', INTEGER_REGEXPS, Number, function (s) { return (s === undefined ? null : Number(s)); }, false, false, true));
    registry.defineParameterType(new ParameterType_js_1.default('short', INTEGER_REGEXPS, Number, function (s) { return (s === undefined ? null : Number(s)); }, false, false, true));
    registry.defineParameterType(new ParameterType_js_1.default('long', INTEGER_REGEXPS, Number, function (s) { return (s === undefined ? null : Number(s)); }, false, false, true));
    registry.defineParameterType(new ParameterType_js_1.default('biginteger', INTEGER_REGEXPS, BigInt, function (s) { return (s === undefined ? null : BigInt(s)); }, false, false, true));
}
exports.default = defineDefaultParameterTypes;
//# sourceMappingURL=defineDefaultParameterTypes.js.map