import Argument from './Argument.js';
import ParameterType from './ParameterType.js';
import TreeRegexp from './TreeRegexp.js';
export default class RegularExpression {
    constructor(regexp, parameterTypeRegistry) {
        this.regexp = regexp;
        this.parameterTypeRegistry = parameterTypeRegistry;
        this.treeRegexp = new TreeRegexp(regexp);
    }
    match(text) {
        const group = this.treeRegexp.match(text);
        if (!group) {
            return null;
        }
        const parameterTypes = this.treeRegexp.groupBuilder.children.map((groupBuilder) => {
            const parameterTypeRegexp = groupBuilder.source;
            const parameterType = this.parameterTypeRegistry.lookupByRegexp(parameterTypeRegexp, this.regexp, text);
            return (parameterType ||
                new ParameterType(undefined, parameterTypeRegexp, String, (s) => (s === undefined ? null : s), false, false));
        });
        return Argument.build(group, parameterTypes);
    }
    get source() {
        return this.regexp.source;
    }
}
//# sourceMappingURL=RegularExpression.js.map