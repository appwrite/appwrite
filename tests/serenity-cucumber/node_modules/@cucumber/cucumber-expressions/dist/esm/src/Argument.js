import CucumberExpressionError from './CucumberExpressionError.js';
export default class Argument {
    static build(group, parameterTypes) {
        const argGroups = group.children;
        if (argGroups.length !== parameterTypes.length) {
            throw new CucumberExpressionError(`Group has ${argGroups.length} capture groups (${argGroups.map((g) => g.value)}), but there were ${parameterTypes.length} parameter types (${parameterTypes.map((p) => p.name)})`);
        }
        return parameterTypes.map((parameterType, i) => new Argument(argGroups[i], parameterType));
    }
    constructor(group, parameterType) {
        this.group = group;
        this.parameterType = parameterType;
        this.group = group;
        this.parameterType = parameterType;
    }
    /**
     * Get the value returned by the parameter type's transformer function.
     *
     * @param thisObj the object in which the transformer function is applied.
     */
    getValue(thisObj) {
        const groupValues = this.group ? this.group.values : null;
        return this.parameterType.transform(thisObj, groupValues);
    }
    getParameterType() {
        return this.parameterType;
    }
}
//# sourceMappingURL=Argument.js.map