import { purposeOf, symbolOf } from './Ast.js';
import CucumberExpressionError from './CucumberExpressionError.js';
export function createAlternativeMayNotExclusivelyContainOptionals(node, expression) {
    return new CucumberExpressionError(message(node.start, expression, pointAtLocated(node), 'An alternative may not exclusively contain optionals', "If you did not mean to use an optional you can use '\\(' to escape the the '('"));
}
export function createAlternativeMayNotBeEmpty(node, expression) {
    return new CucumberExpressionError(message(node.start, expression, pointAtLocated(node), 'Alternative may not be empty', "If you did not mean to use an alternative you can use '\\/' to escape the the '/'"));
}
export function createOptionalMayNotBeEmpty(node, expression) {
    return new CucumberExpressionError(message(node.start, expression, pointAtLocated(node), 'An optional must contain some text', "If you did not mean to use an optional you can use '\\(' to escape the the '('"));
}
export function createParameterIsNotAllowedInOptional(node, expression) {
    return new CucumberExpressionError(message(node.start, expression, pointAtLocated(node), 'An optional may not contain a parameter type', "If you did not mean to use an parameter type you can use '\\{' to escape the the '{'"));
}
export function createOptionalIsNotAllowedInOptional(node, expression) {
    return new CucumberExpressionError(message(node.start, expression, pointAtLocated(node), 'An optional may not contain an other optional', "If you did not mean to use an optional type you can use '\\(' to escape the the '('. For more complicated expressions consider using a regular expression instead."));
}
export function createTheEndOfLIneCanNotBeEscaped(expression) {
    const index = Array.from(expression).length - 1;
    return new CucumberExpressionError(message(index, expression, pointAt(index), 'The end of line can not be escaped', "You can use '\\\\' to escape the the '\\'"));
}
export function createMissingEndToken(expression, beginToken, endToken, current) {
    const beginSymbol = symbolOf(beginToken);
    const endSymbol = symbolOf(endToken);
    const purpose = purposeOf(beginToken);
    return new CucumberExpressionError(message(current.start, expression, pointAtLocated(current), `The '${beginSymbol}' does not have a matching '${endSymbol}'`, `If you did not intend to use ${purpose} you can use '\\${beginSymbol}' to escape the ${purpose}`));
}
export function createAlternationNotAllowedInOptional(expression, current) {
    return new CucumberExpressionError(message(current.start, expression, pointAtLocated(current), 'An alternation can not be used inside an optional', "You can use '\\/' to escape the the '/'"));
}
export function createCantEscaped(expression, index) {
    return new CucumberExpressionError(message(index, expression, pointAt(index), "Only the characters '{', '}', '(', ')', '\\', '/' and whitespace can be escaped", "If you did mean to use an '\\' you can use '\\\\' to escape it"));
}
export function createInvalidParameterTypeNameInNode(token, expression) {
    return new CucumberExpressionError(message(token.start, expression, pointAtLocated(token), "Parameter names may not contain '{', '}', '(', ')', '\\' or '/'", 'Did you mean to use a regular expression?'));
}
function message(index, expression, pointer, problem, solution) {
    return `This Cucumber Expression has a problem at column ${index + 1}:

${expression}
${pointer}
${problem}.
${solution}`;
}
function pointAt(index) {
    const pointer = [];
    for (let i = 0; i < index; i++) {
        pointer.push(' ');
    }
    pointer.push('^');
    return pointer.join('');
}
function pointAtLocated(node) {
    const pointer = [pointAt(node.start)];
    if (node.start + 1 < node.end) {
        for (let i = node.start + 1; i < node.end - 1; i++) {
            pointer.push('-');
        }
        pointer.push('^');
    }
    return pointer.join('');
}
export class AmbiguousParameterTypeError extends CucumberExpressionError {
    static forRegExp(parameterTypeRegexp, expressionRegexp, parameterTypes, generatedExpressions) {
        return new this(`Your Regular Expression ${expressionRegexp}
matches multiple parameter types with regexp ${parameterTypeRegexp}:
   ${this._parameterTypeNames(parameterTypes)}

I couldn't decide which one to use. You have two options:

1) Use a Cucumber Expression instead of a Regular Expression. Try one of these:
   ${this._expressions(generatedExpressions)}

2) Make one of the parameter types preferential and continue to use a Regular Expression.
`);
    }
    static _parameterTypeNames(parameterTypes) {
        return parameterTypes.map((p) => `{${p.name}}`).join('\n   ');
    }
    static _expressions(generatedExpressions) {
        return generatedExpressions.map((e) => e.source).join('\n   ');
    }
}
export class UndefinedParameterTypeError extends CucumberExpressionError {
    constructor(undefinedParameterTypeName, message) {
        super(message);
        this.undefinedParameterTypeName = undefinedParameterTypeName;
    }
}
export function createUndefinedParameterType(node, expression, undefinedParameterTypeName) {
    return new UndefinedParameterTypeError(undefinedParameterTypeName, message(node.start, expression, pointAtLocated(node), `Undefined parameter type '${undefinedParameterTypeName}'`, `Please register a ParameterType for '${undefinedParameterTypeName}'`));
}
//# sourceMappingURL=Errors.js.map