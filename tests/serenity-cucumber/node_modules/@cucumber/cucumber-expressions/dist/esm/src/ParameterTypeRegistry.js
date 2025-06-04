import CucumberExpressionError from './CucumberExpressionError.js';
import CucumberExpressionGenerator from './CucumberExpressionGenerator.js';
import defineDefaultParameterTypes from './defineDefaultParameterTypes.js';
import { AmbiguousParameterTypeError } from './Errors.js';
import ParameterType from './ParameterType.js';
export default class ParameterTypeRegistry {
    constructor() {
        this.parameterTypeByName = new Map();
        this.parameterTypesByRegexp = new Map();
        defineDefaultParameterTypes(this);
    }
    get parameterTypes() {
        return this.parameterTypeByName.values();
    }
    lookupByTypeName(typeName) {
        return this.parameterTypeByName.get(typeName);
    }
    lookupByRegexp(parameterTypeRegexp, expressionRegexp, text) {
        const parameterTypes = this.parameterTypesByRegexp.get(parameterTypeRegexp);
        if (!parameterTypes) {
            return undefined;
        }
        if (parameterTypes.length > 1 && !parameterTypes[0].preferForRegexpMatch) {
            // We don't do this check on insertion because we only want to restrict
            // ambiguity when we look up by Regexp. Users of CucumberExpression should
            // not be restricted.
            const generatedExpressions = new CucumberExpressionGenerator(() => this.parameterTypes).generateExpressions(text);
            throw AmbiguousParameterTypeError.forRegExp(parameterTypeRegexp, expressionRegexp, parameterTypes, generatedExpressions);
        }
        return parameterTypes[0];
    }
    defineParameterType(parameterType) {
        if (parameterType.name !== undefined) {
            if (this.parameterTypeByName.has(parameterType.name)) {
                if (parameterType.name.length === 0) {
                    throw new CucumberExpressionError(`The anonymous parameter type has already been defined`);
                }
                else {
                    throw new CucumberExpressionError(`There is already a parameter type with name ${parameterType.name}`);
                }
            }
            this.parameterTypeByName.set(parameterType.name, parameterType);
        }
        for (const parameterTypeRegexp of parameterType.regexpStrings) {
            if (!this.parameterTypesByRegexp.has(parameterTypeRegexp)) {
                this.parameterTypesByRegexp.set(parameterTypeRegexp, []);
            }
            // eslint-disable-next-line @typescript-eslint/no-non-null-assertion
            const parameterTypes = this.parameterTypesByRegexp.get(parameterTypeRegexp);
            const existingParameterType = parameterTypes[0];
            if (parameterTypes.length > 0 &&
                existingParameterType.preferForRegexpMatch &&
                parameterType.preferForRegexpMatch) {
                throw new CucumberExpressionError('There can only be one preferential parameter type per regexp. ' +
                    `The regexp /${parameterTypeRegexp}/ is used for two preferential parameter types, {${existingParameterType.name}} and {${parameterType.name}}`);
            }
            if (parameterTypes.indexOf(parameterType) === -1) {
                parameterTypes.push(parameterType);
                this.parameterTypesByRegexp.set(parameterTypeRegexp, parameterTypes.sort(ParameterType.compare));
            }
        }
    }
}
//# sourceMappingURL=ParameterTypeRegistry.js.map