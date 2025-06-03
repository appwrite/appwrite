import { Node, Token, TokenType } from './Ast.js';
import CucumberExpressionError from './CucumberExpressionError.js';
import GeneratedExpression from './GeneratedExpression.js';
import ParameterType from './ParameterType.js';
export declare function createAlternativeMayNotExclusivelyContainOptionals(node: Node, expression: string): CucumberExpressionError;
export declare function createAlternativeMayNotBeEmpty(node: Node, expression: string): CucumberExpressionError;
export declare function createOptionalMayNotBeEmpty(node: Node, expression: string): CucumberExpressionError;
export declare function createParameterIsNotAllowedInOptional(node: Node, expression: string): CucumberExpressionError;
export declare function createOptionalIsNotAllowedInOptional(node: Node, expression: string): CucumberExpressionError;
export declare function createTheEndOfLIneCanNotBeEscaped(expression: string): CucumberExpressionError;
export declare function createMissingEndToken(expression: string, beginToken: TokenType, endToken: TokenType, current: Token): CucumberExpressionError;
export declare function createAlternationNotAllowedInOptional(expression: string, current: Token): CucumberExpressionError;
export declare function createCantEscaped(expression: string, index: number): CucumberExpressionError;
export declare function createInvalidParameterTypeNameInNode(token: Token, expression: string): CucumberExpressionError;
export declare class AmbiguousParameterTypeError extends CucumberExpressionError {
    static forRegExp(parameterTypeRegexp: string, expressionRegexp: RegExp, parameterTypes: readonly ParameterType<unknown>[], generatedExpressions: readonly GeneratedExpression[]): AmbiguousParameterTypeError;
    static _parameterTypeNames(parameterTypes: readonly ParameterType<unknown>[]): string;
    static _expressions(generatedExpressions: readonly GeneratedExpression[]): string;
}
export declare class UndefinedParameterTypeError extends CucumberExpressionError {
    readonly undefinedParameterTypeName: string;
    constructor(undefinedParameterTypeName: string, message: string);
}
export declare function createUndefinedParameterType(node: Node, expression: string, undefinedParameterTypeName: string): UndefinedParameterTypeError;
//# sourceMappingURL=Errors.d.ts.map