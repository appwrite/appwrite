"use strict";
var __extends = (this && this.__extends) || (function () {
    var extendStatics = function (d, b) {
        extendStatics = Object.setPrototypeOf ||
            ({ __proto__: [] } instanceof Array && function (d, b) { d.__proto__ = b; }) ||
            function (d, b) { for (var p in b) if (Object.prototype.hasOwnProperty.call(b, p)) d[p] = b[p]; };
        return extendStatics(d, b);
    };
    return function (d, b) {
        if (typeof b !== "function" && b !== null)
            throw new TypeError("Class extends value " + String(b) + " is not a constructor or null");
        extendStatics(d, b);
        function __() { this.constructor = d; }
        d.prototype = b === null ? Object.create(b) : (__.prototype = b.prototype, new __());
    };
})();
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.createUndefinedParameterType = exports.UndefinedParameterTypeError = exports.AmbiguousParameterTypeError = exports.createInvalidParameterTypeNameInNode = exports.createCantEscaped = exports.createAlternationNotAllowedInOptional = exports.createMissingEndToken = exports.createTheEndOfLIneCanNotBeEscaped = exports.createOptionalIsNotAllowedInOptional = exports.createParameterIsNotAllowedInOptional = exports.createOptionalMayNotBeEmpty = exports.createAlternativeMayNotBeEmpty = exports.createAlternativeMayNotExclusivelyContainOptionals = void 0;
var Ast_js_1 = require("./Ast.js");
var CucumberExpressionError_js_1 = __importDefault(require("./CucumberExpressionError.js"));
function createAlternativeMayNotExclusivelyContainOptionals(node, expression) {
    return new CucumberExpressionError_js_1.default(message(node.start, expression, pointAtLocated(node), 'An alternative may not exclusively contain optionals', "If you did not mean to use an optional you can use '\\(' to escape the the '('"));
}
exports.createAlternativeMayNotExclusivelyContainOptionals = createAlternativeMayNotExclusivelyContainOptionals;
function createAlternativeMayNotBeEmpty(node, expression) {
    return new CucumberExpressionError_js_1.default(message(node.start, expression, pointAtLocated(node), 'Alternative may not be empty', "If you did not mean to use an alternative you can use '\\/' to escape the the '/'"));
}
exports.createAlternativeMayNotBeEmpty = createAlternativeMayNotBeEmpty;
function createOptionalMayNotBeEmpty(node, expression) {
    return new CucumberExpressionError_js_1.default(message(node.start, expression, pointAtLocated(node), 'An optional must contain some text', "If you did not mean to use an optional you can use '\\(' to escape the the '('"));
}
exports.createOptionalMayNotBeEmpty = createOptionalMayNotBeEmpty;
function createParameterIsNotAllowedInOptional(node, expression) {
    return new CucumberExpressionError_js_1.default(message(node.start, expression, pointAtLocated(node), 'An optional may not contain a parameter type', "If you did not mean to use an parameter type you can use '\\{' to escape the the '{'"));
}
exports.createParameterIsNotAllowedInOptional = createParameterIsNotAllowedInOptional;
function createOptionalIsNotAllowedInOptional(node, expression) {
    return new CucumberExpressionError_js_1.default(message(node.start, expression, pointAtLocated(node), 'An optional may not contain an other optional', "If you did not mean to use an optional type you can use '\\(' to escape the the '('. For more complicated expressions consider using a regular expression instead."));
}
exports.createOptionalIsNotAllowedInOptional = createOptionalIsNotAllowedInOptional;
function createTheEndOfLIneCanNotBeEscaped(expression) {
    var index = Array.from(expression).length - 1;
    return new CucumberExpressionError_js_1.default(message(index, expression, pointAt(index), 'The end of line can not be escaped', "You can use '\\\\' to escape the the '\\'"));
}
exports.createTheEndOfLIneCanNotBeEscaped = createTheEndOfLIneCanNotBeEscaped;
function createMissingEndToken(expression, beginToken, endToken, current) {
    var beginSymbol = (0, Ast_js_1.symbolOf)(beginToken);
    var endSymbol = (0, Ast_js_1.symbolOf)(endToken);
    var purpose = (0, Ast_js_1.purposeOf)(beginToken);
    return new CucumberExpressionError_js_1.default(message(current.start, expression, pointAtLocated(current), "The '".concat(beginSymbol, "' does not have a matching '").concat(endSymbol, "'"), "If you did not intend to use ".concat(purpose, " you can use '\\").concat(beginSymbol, "' to escape the ").concat(purpose)));
}
exports.createMissingEndToken = createMissingEndToken;
function createAlternationNotAllowedInOptional(expression, current) {
    return new CucumberExpressionError_js_1.default(message(current.start, expression, pointAtLocated(current), 'An alternation can not be used inside an optional', "You can use '\\/' to escape the the '/'"));
}
exports.createAlternationNotAllowedInOptional = createAlternationNotAllowedInOptional;
function createCantEscaped(expression, index) {
    return new CucumberExpressionError_js_1.default(message(index, expression, pointAt(index), "Only the characters '{', '}', '(', ')', '\\', '/' and whitespace can be escaped", "If you did mean to use an '\\' you can use '\\\\' to escape it"));
}
exports.createCantEscaped = createCantEscaped;
function createInvalidParameterTypeNameInNode(token, expression) {
    return new CucumberExpressionError_js_1.default(message(token.start, expression, pointAtLocated(token), "Parameter names may not contain '{', '}', '(', ')', '\\' or '/'", 'Did you mean to use a regular expression?'));
}
exports.createInvalidParameterTypeNameInNode = createInvalidParameterTypeNameInNode;
function message(index, expression, pointer, problem, solution) {
    return "This Cucumber Expression has a problem at column ".concat(index + 1, ":\n\n").concat(expression, "\n").concat(pointer, "\n").concat(problem, ".\n").concat(solution);
}
function pointAt(index) {
    var pointer = [];
    for (var i = 0; i < index; i++) {
        pointer.push(' ');
    }
    pointer.push('^');
    return pointer.join('');
}
function pointAtLocated(node) {
    var pointer = [pointAt(node.start)];
    if (node.start + 1 < node.end) {
        for (var i = node.start + 1; i < node.end - 1; i++) {
            pointer.push('-');
        }
        pointer.push('^');
    }
    return pointer.join('');
}
var AmbiguousParameterTypeError = /** @class */ (function (_super) {
    __extends(AmbiguousParameterTypeError, _super);
    function AmbiguousParameterTypeError() {
        return _super !== null && _super.apply(this, arguments) || this;
    }
    AmbiguousParameterTypeError.forRegExp = function (parameterTypeRegexp, expressionRegexp, parameterTypes, generatedExpressions) {
        return new this("Your Regular Expression ".concat(expressionRegexp, "\nmatches multiple parameter types with regexp ").concat(parameterTypeRegexp, ":\n   ").concat(this._parameterTypeNames(parameterTypes), "\n\nI couldn't decide which one to use. You have two options:\n\n1) Use a Cucumber Expression instead of a Regular Expression. Try one of these:\n   ").concat(this._expressions(generatedExpressions), "\n\n2) Make one of the parameter types preferential and continue to use a Regular Expression.\n"));
    };
    AmbiguousParameterTypeError._parameterTypeNames = function (parameterTypes) {
        return parameterTypes.map(function (p) { return "{".concat(p.name, "}"); }).join('\n   ');
    };
    AmbiguousParameterTypeError._expressions = function (generatedExpressions) {
        return generatedExpressions.map(function (e) { return e.source; }).join('\n   ');
    };
    return AmbiguousParameterTypeError;
}(CucumberExpressionError_js_1.default));
exports.AmbiguousParameterTypeError = AmbiguousParameterTypeError;
var UndefinedParameterTypeError = /** @class */ (function (_super) {
    __extends(UndefinedParameterTypeError, _super);
    function UndefinedParameterTypeError(undefinedParameterTypeName, message) {
        var _this = _super.call(this, message) || this;
        _this.undefinedParameterTypeName = undefinedParameterTypeName;
        return _this;
    }
    return UndefinedParameterTypeError;
}(CucumberExpressionError_js_1.default));
exports.UndefinedParameterTypeError = UndefinedParameterTypeError;
function createUndefinedParameterType(node, expression, undefinedParameterTypeName) {
    return new UndefinedParameterTypeError(undefinedParameterTypeName, message(node.start, expression, pointAtLocated(node), "Undefined parameter type '".concat(undefinedParameterTypeName, "'"), "Please register a ParameterType for '".concat(undefinedParameterTypeName, "'")));
}
exports.createUndefinedParameterType = createUndefinedParameterType;
//# sourceMappingURL=Errors.js.map