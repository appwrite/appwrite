export default class GeneratedExpression {
    constructor(expressionTemplate, parameterTypes) {
        this.expressionTemplate = expressionTemplate;
        this.parameterTypes = parameterTypes;
    }
    get source() {
        return format(this.expressionTemplate, ...this.parameterTypes.map((t) => t.name || ''));
    }
    /**
     * Returns an array of parameter names to use in generated function/method signatures
     *
     * @returns {ReadonlyArray.<String>}
     */
    get parameterNames() {
        return this.parameterInfos.map((i) => `${i.name}${i.count === 1 ? '' : i.count.toString()}`);
    }
    /**
     * Returns an array of ParameterInfo to use in generated function/method signatures
     */
    get parameterInfos() {
        const usageByTypeName = {};
        return this.parameterTypes.map((t) => getParameterInfo(t, usageByTypeName));
    }
}
function getParameterInfo(parameterType, usageByName) {
    const name = parameterType.name || '';
    let counter = usageByName[name];
    counter = counter ? counter + 1 : 1;
    usageByName[name] = counter;
    let type;
    if (parameterType.type) {
        if (typeof parameterType.type === 'string') {
            type = parameterType.type;
        }
        else if ('name' in parameterType.type) {
            type = parameterType.type.name;
        }
        else {
            type = null;
        }
    }
    else {
        type = null;
    }
    return {
        type,
        name,
        count: counter,
    };
}
function format(pattern, ...args) {
    return pattern.replace(/{(\d+)}/g, (match, number) => args[number]);
}
//# sourceMappingURL=GeneratedExpression.js.map