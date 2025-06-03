"use strict";
var __read = (this && this.__read) || function (o, n) {
    var m = typeof Symbol === "function" && o[Symbol.iterator];
    if (!m) return o;
    var i = m.call(o), r, ar = [], e;
    try {
        while ((n === void 0 || n-- > 0) && !(r = i.next()).done) ar.push(r.value);
    }
    catch (error) { e = { error: error }; }
    finally {
        try {
            if (r && !r.done && (m = i["return"])) m.call(i);
        }
        finally { if (e) throw e.error; }
    }
    return ar;
};
var __spreadArray = (this && this.__spreadArray) || function (to, from, pack) {
    if (pack || arguments.length === 2) for (var i = 0, l = from.length, ar; i < l; i++) {
        if (ar || !(i in from)) {
            if (!ar) ar = Array.prototype.slice.call(from, 0, i);
            ar[i] = from[i];
        }
    }
    return to.concat(ar || Array.prototype.slice.call(from));
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var Ast_js_1 = require("./Ast.js");
var CucumberExpressionTokenizer_js_1 = __importDefault(require("./CucumberExpressionTokenizer.js"));
var Errors_js_1 = require("./Errors.js");
/*
 * text := whitespace | ')' | '}' | .
 */
function parseText(expression, tokens, current) {
    var token = tokens[current];
    switch (token.type) {
        case Ast_js_1.TokenType.whiteSpace:
        case Ast_js_1.TokenType.text:
        case Ast_js_1.TokenType.endParameter:
        case Ast_js_1.TokenType.endOptional:
            return {
                consumed: 1,
                ast: [new Ast_js_1.Node(Ast_js_1.NodeType.text, undefined, token.text, token.start, token.end)],
            };
        case Ast_js_1.TokenType.alternation:
            throw (0, Errors_js_1.createAlternationNotAllowedInOptional)(expression, token);
        case Ast_js_1.TokenType.startOfLine:
        case Ast_js_1.TokenType.endOfLine:
        case Ast_js_1.TokenType.beginOptional:
        case Ast_js_1.TokenType.beginParameter:
        default:
            // If configured correctly this will never happen
            return { consumed: 0, ast: [] };
    }
}
/*
 * parameter := '{' + name* + '}'
 */
function parseName(expression, tokens, current) {
    var token = tokens[current];
    switch (token.type) {
        case Ast_js_1.TokenType.whiteSpace:
        case Ast_js_1.TokenType.text:
            return {
                consumed: 1,
                ast: [new Ast_js_1.Node(Ast_js_1.NodeType.text, undefined, token.text, token.start, token.end)],
            };
        case Ast_js_1.TokenType.beginOptional:
        case Ast_js_1.TokenType.endOptional:
        case Ast_js_1.TokenType.beginParameter:
        case Ast_js_1.TokenType.endParameter:
        case Ast_js_1.TokenType.alternation:
            throw (0, Errors_js_1.createInvalidParameterTypeNameInNode)(token, expression);
        case Ast_js_1.TokenType.startOfLine:
        case Ast_js_1.TokenType.endOfLine:
        default:
            // If configured correctly this will never happen
            return { consumed: 0, ast: [] };
    }
}
/*
 * parameter := '{' + text* + '}'
 */
var parseParameter = parseBetween(Ast_js_1.NodeType.parameter, Ast_js_1.TokenType.beginParameter, Ast_js_1.TokenType.endParameter, [parseName]);
/*
 * optional := '(' + option* + ')'
 * option := optional | parameter | text
 */
var optionalSubParsers = [];
var parseOptional = parseBetween(Ast_js_1.NodeType.optional, Ast_js_1.TokenType.beginOptional, Ast_js_1.TokenType.endOptional, optionalSubParsers);
optionalSubParsers.push(parseOptional, parseParameter, parseText);
/*
 * alternation := alternative* + ( '/' + alternative* )+
 */
function parseAlternativeSeparator(expression, tokens, current) {
    if (!lookingAt(tokens, current, Ast_js_1.TokenType.alternation)) {
        return { consumed: 0, ast: [] };
    }
    var token = tokens[current];
    return {
        consumed: 1,
        ast: [new Ast_js_1.Node(Ast_js_1.NodeType.alternative, undefined, token.text, token.start, token.end)],
    };
}
var alternativeParsers = [
    parseAlternativeSeparator,
    parseOptional,
    parseParameter,
    parseText,
];
/*
 * alternation := (?<=left-boundary) + alternative* + ( '/' + alternative* )+ + (?=right-boundary)
 * left-boundary := whitespace | } | ^
 * right-boundary := whitespace | { | $
 * alternative: = optional | parameter | text
 */
var parseAlternation = function (expression, tokens, current) {
    var previous = current - 1;
    if (!lookingAtAny(tokens, previous, [
        Ast_js_1.TokenType.startOfLine,
        Ast_js_1.TokenType.whiteSpace,
        Ast_js_1.TokenType.endParameter,
    ])) {
        return { consumed: 0, ast: [] };
    }
    var result = parseTokensUntil(expression, alternativeParsers, tokens, current, [
        Ast_js_1.TokenType.whiteSpace,
        Ast_js_1.TokenType.endOfLine,
        Ast_js_1.TokenType.beginParameter,
    ]);
    var subCurrent = current + result.consumed;
    if (!result.ast.some(function (astNode) { return astNode.type == Ast_js_1.NodeType.alternative; })) {
        return { consumed: 0, ast: [] };
    }
    var start = tokens[current].start;
    var end = tokens[subCurrent].start;
    // Does not consume right hand boundary token
    return {
        consumed: result.consumed,
        ast: [
            new Ast_js_1.Node(Ast_js_1.NodeType.alternation, splitAlternatives(start, end, result.ast), undefined, start, end),
        ],
    };
};
/*
 * cucumber-expression :=  ( alternation | optional | parameter | text )*
 */
var parseCucumberExpression = parseBetween(Ast_js_1.NodeType.expression, Ast_js_1.TokenType.startOfLine, Ast_js_1.TokenType.endOfLine, [parseAlternation, parseOptional, parseParameter, parseText]);
var CucumberExpressionParser = /** @class */ (function () {
    function CucumberExpressionParser() {
    }
    CucumberExpressionParser.prototype.parse = function (expression) {
        var tokenizer = new CucumberExpressionTokenizer_js_1.default();
        var tokens = tokenizer.tokenize(expression);
        var result = parseCucumberExpression(expression, tokens, 0);
        return result.ast[0];
    };
    return CucumberExpressionParser;
}());
exports.default = CucumberExpressionParser;
function parseBetween(type, beginToken, endToken, parsers) {
    return function (expression, tokens, current) {
        if (!lookingAt(tokens, current, beginToken)) {
            return { consumed: 0, ast: [] };
        }
        var subCurrent = current + 1;
        var result = parseTokensUntil(expression, parsers, tokens, subCurrent, [
            endToken,
            Ast_js_1.TokenType.endOfLine,
        ]);
        subCurrent += result.consumed;
        // endToken not found
        if (!lookingAt(tokens, subCurrent, endToken)) {
            throw (0, Errors_js_1.createMissingEndToken)(expression, beginToken, endToken, tokens[current]);
        }
        // consumes endToken
        var start = tokens[current].start;
        var end = tokens[subCurrent].end;
        var consumed = subCurrent + 1 - current;
        var ast = [new Ast_js_1.Node(type, result.ast, undefined, start, end)];
        return { consumed: consumed, ast: ast };
    };
}
function parseToken(expression, parsers, tokens, startAt) {
    for (var i = 0; i < parsers.length; i++) {
        var parse = parsers[i];
        var result = parse(expression, tokens, startAt);
        if (result.consumed != 0) {
            return result;
        }
    }
    // If configured correctly this will never happen
    throw new Error('No eligible parsers for ' + tokens);
}
function parseTokensUntil(expression, parsers, tokens, startAt, endTokens) {
    var current = startAt;
    var size = tokens.length;
    var ast = [];
    while (current < size) {
        if (lookingAtAny(tokens, current, endTokens)) {
            break;
        }
        var result = parseToken(expression, parsers, tokens, current);
        if (result.consumed == 0) {
            // If configured correctly this will never happen
            // Keep to avoid infinite loops
            throw new Error('No eligible parsers for ' + tokens);
        }
        current += result.consumed;
        ast.push.apply(ast, __spreadArray([], __read(result.ast), false));
    }
    return { consumed: current - startAt, ast: ast };
}
function lookingAtAny(tokens, at, tokenTypes) {
    return tokenTypes.some(function (tokenType) { return lookingAt(tokens, at, tokenType); });
}
function lookingAt(tokens, at, token) {
    if (at < 0) {
        // If configured correctly this will never happen
        // Keep for completeness
        return token == Ast_js_1.TokenType.startOfLine;
    }
    if (at >= tokens.length) {
        return token == Ast_js_1.TokenType.endOfLine;
    }
    return tokens[at].type == token;
}
function splitAlternatives(start, end, alternation) {
    var separators = [];
    var alternatives = [];
    var alternative = [];
    alternation.forEach(function (n) {
        if (Ast_js_1.NodeType.alternative == n.type) {
            separators.push(n);
            alternatives.push(alternative);
            alternative = [];
        }
        else {
            alternative.push(n);
        }
    });
    alternatives.push(alternative);
    return createAlternativeNodes(start, end, separators, alternatives);
}
function createAlternativeNodes(start, end, separators, alternatives) {
    var nodes = [];
    for (var i = 0; i < alternatives.length; i++) {
        var n = alternatives[i];
        if (i == 0) {
            var rightSeparator = separators[i];
            nodes.push(new Ast_js_1.Node(Ast_js_1.NodeType.alternative, n, undefined, start, rightSeparator.start));
        }
        else if (i == alternatives.length - 1) {
            var leftSeparator = separators[i - 1];
            nodes.push(new Ast_js_1.Node(Ast_js_1.NodeType.alternative, n, undefined, leftSeparator.end, end));
        }
        else {
            var leftSeparator = separators[i - 1];
            var rightSeparator = separators[i];
            nodes.push(new Ast_js_1.Node(Ast_js_1.NodeType.alternative, n, undefined, leftSeparator.end, rightSeparator.start));
        }
    }
    return nodes;
}
//# sourceMappingURL=CucumberExpressionParser.js.map