import ParameterType from './ParameterType.js';
const INTEGER_REGEXPS = [/-?\d+/, /\d+/];
const FLOAT_REGEXP = /(?=.*\d.*)[-+]?\d*(?:\.(?=\d.*))?\d*(?:\d+[E][+-]?\d+)?/;
const WORD_REGEXP = /[^\s]+/;
const STRING_REGEXP = /"([^"\\]*(\\.[^"\\]*)*)"|'([^'\\]*(\\.[^'\\]*)*)'/;
const ANONYMOUS_REGEXP = /.*/;
export default function defineDefaultParameterTypes(registry) {
    registry.defineParameterType(new ParameterType('int', INTEGER_REGEXPS, Number, (s) => (s === undefined ? null : Number(s)), true, true, true));
    registry.defineParameterType(new ParameterType('float', FLOAT_REGEXP, Number, (s) => (s === undefined ? null : parseFloat(s)), true, false, true));
    registry.defineParameterType(new ParameterType('word', WORD_REGEXP, String, (s) => s, false, false, true));
    registry.defineParameterType(new ParameterType('string', STRING_REGEXP, String, (s1, s2) => (s1 || s2 || '').replace(/\\"/g, '"').replace(/\\'/g, "'"), true, false, true));
    registry.defineParameterType(new ParameterType('', ANONYMOUS_REGEXP, String, (s) => s, false, true, true));
    registry.defineParameterType(new ParameterType('double', FLOAT_REGEXP, Number, (s) => (s === undefined ? null : parseFloat(s)), false, false, true));
    registry.defineParameterType(new ParameterType('bigdecimal', FLOAT_REGEXP, String, (s) => (s === undefined ? null : s), false, false, true));
    registry.defineParameterType(new ParameterType('byte', INTEGER_REGEXPS, Number, (s) => (s === undefined ? null : Number(s)), false, false, true));
    registry.defineParameterType(new ParameterType('short', INTEGER_REGEXPS, Number, (s) => (s === undefined ? null : Number(s)), false, false, true));
    registry.defineParameterType(new ParameterType('long', INTEGER_REGEXPS, Number, (s) => (s === undefined ? null : Number(s)), false, false, true));
    registry.defineParameterType(new ParameterType('biginteger', INTEGER_REGEXPS, BigInt, (s) => (s === undefined ? null : BigInt(s)), false, false, true));
}
//# sourceMappingURL=defineDefaultParameterTypes.js.map