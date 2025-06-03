import CucumberExpressionError from './CucumberExpressionError.js';
const ILLEGAL_PARAMETER_NAME_PATTERN = /([[\]()$.|?*+])/;
const UNESCAPE_PATTERN = () => /(\\([[$.|?*+\]]))/g;
export default class ParameterType {
    static compare(pt1, pt2) {
        if (pt1.preferForRegexpMatch && !pt2.preferForRegexpMatch) {
            return -1;
        }
        if (pt2.preferForRegexpMatch && !pt1.preferForRegexpMatch) {
            return 1;
        }
        return (pt1.name || '').localeCompare(pt2.name || '');
    }
    static checkParameterTypeName(typeName) {
        if (!this.isValidParameterTypeName(typeName)) {
            throw new CucumberExpressionError(`Illegal character in parameter name {${typeName}}. Parameter names may not contain '{', '}', '(', ')', '\\' or '/'`);
        }
    }
    static isValidParameterTypeName(typeName) {
        const unescapedTypeName = typeName.replace(UNESCAPE_PATTERN(), '$2');
        return !unescapedTypeName.match(ILLEGAL_PARAMETER_NAME_PATTERN);
    }
    /**
     * @param name {String} the name of the type
     * @param regexps {Array.<RegExp | String>,RegExp,String} that matche the type
     * @param type {Function} the prototype (constructor) of the type. May be null.
     * @param transform {Function} function transforming string to another type. May be null.
     * @param useForSnippets {boolean} true if this should be used for snippets. Defaults to true.
     * @param preferForRegexpMatch {boolean} true if this is a preferential type. Defaults to false.
     * @param builtin whether or not this is a built-in type
     */
    constructor(name, regexps, type, transform, useForSnippets, preferForRegexpMatch, builtin) {
        this.name = name;
        this.type = type;
        this.useForSnippets = useForSnippets;
        this.preferForRegexpMatch = preferForRegexpMatch;
        this.builtin = builtin;
        if (transform === undefined) {
            transform = (s) => s;
        }
        if (useForSnippets === undefined) {
            this.useForSnippets = true;
        }
        if (preferForRegexpMatch === undefined) {
            this.preferForRegexpMatch = false;
        }
        if (name) {
            ParameterType.checkParameterTypeName(name);
        }
        this.regexpStrings = stringArray(regexps);
        this.transformFn = transform;
    }
    transform(thisObj, groupValues) {
        return this.transformFn.apply(thisObj, groupValues);
    }
}
function stringArray(regexps) {
    const array = Array.isArray(regexps) ? regexps : [regexps];
    return array.map((r) => (r instanceof RegExp ? regexpSource(r) : r));
}
function regexpSource(regexp) {
    const flags = regexpFlags(regexp);
    for (const flag of ['g', 'i', 'm', 'y']) {
        if (flags.indexOf(flag) !== -1) {
            throw new CucumberExpressionError(`ParameterType Regexps can't use flag '${flag}'`);
        }
    }
    return regexp.source;
}
// Backport RegExp.flags for Node 4.x
// https://github.com/nodejs/node/issues/8390
function regexpFlags(regexp) {
    let flags = regexp.flags;
    if (flags === undefined) {
        flags = '';
        if (regexp.ignoreCase) {
            flags += 'i';
        }
        if (regexp.global) {
            flags += 'g';
        }
        if (regexp.multiline) {
            flags += 'm';
        }
    }
    return flags;
}
//# sourceMappingURL=ParameterType.js.map