import { Node, NodeType, TokenType } from './Ast.js';
import CucumberExpressionTokenizer from './CucumberExpressionTokenizer.js';
import { createAlternationNotAllowedInOptional, createInvalidParameterTypeNameInNode, createMissingEndToken, } from './Errors.js';
/*
 * text := whitespace | ')' | '}' | .
 */
function parseText(expression, tokens, current) {
    const token = tokens[current];
    switch (token.type) {
        case TokenType.whiteSpace:
        case TokenType.text:
        case TokenType.endParameter:
        case TokenType.endOptional:
            return {
                consumed: 1,
                ast: [new Node(NodeType.text, undefined, token.text, token.start, token.end)],
            };
        case TokenType.alternation:
            throw createAlternationNotAllowedInOptional(expression, token);
        case TokenType.startOfLine:
        case TokenType.endOfLine:
        case TokenType.beginOptional:
        case TokenType.beginParameter:
        default:
            // If configured correctly this will never happen
            return { consumed: 0, ast: [] };
    }
}
/*
 * parameter := '{' + name* + '}'
 */
function parseName(expression, tokens, current) {
    const token = tokens[current];
    switch (token.type) {
        case TokenType.whiteSpace:
        case TokenType.text:
            return {
                consumed: 1,
                ast: [new Node(NodeType.text, undefined, token.text, token.start, token.end)],
            };
        case TokenType.beginOptional:
        case TokenType.endOptional:
        case TokenType.beginParameter:
        case TokenType.endParameter:
        case TokenType.alternation:
            throw createInvalidParameterTypeNameInNode(token, expression);
        case TokenType.startOfLine:
        case TokenType.endOfLine:
        default:
            // If configured correctly this will never happen
            return { consumed: 0, ast: [] };
    }
}
/*
 * parameter := '{' + text* + '}'
 */
const parseParameter = parseBetween(NodeType.parameter, TokenType.beginParameter, TokenType.endParameter, [parseName]);
/*
 * optional := '(' + option* + ')'
 * option := optional | parameter | text
 */
const optionalSubParsers = [];
const parseOptional = parseBetween(NodeType.optional, TokenType.beginOptional, TokenType.endOptional, optionalSubParsers);
optionalSubParsers.push(parseOptional, parseParameter, parseText);
/*
 * alternation := alternative* + ( '/' + alternative* )+
 */
function parseAlternativeSeparator(expression, tokens, current) {
    if (!lookingAt(tokens, current, TokenType.alternation)) {
        return { consumed: 0, ast: [] };
    }
    const token = tokens[current];
    return {
        consumed: 1,
        ast: [new Node(NodeType.alternative, undefined, token.text, token.start, token.end)],
    };
}
const alternativeParsers = [
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
const parseAlternation = (expression, tokens, current) => {
    const previous = current - 1;
    if (!lookingAtAny(tokens, previous, [
        TokenType.startOfLine,
        TokenType.whiteSpace,
        TokenType.endParameter,
    ])) {
        return { consumed: 0, ast: [] };
    }
    const result = parseTokensUntil(expression, alternativeParsers, tokens, current, [
        TokenType.whiteSpace,
        TokenType.endOfLine,
        TokenType.beginParameter,
    ]);
    const subCurrent = current + result.consumed;
    if (!result.ast.some((astNode) => astNode.type == NodeType.alternative)) {
        return { consumed: 0, ast: [] };
    }
    const start = tokens[current].start;
    const end = tokens[subCurrent].start;
    // Does not consume right hand boundary token
    return {
        consumed: result.consumed,
        ast: [
            new Node(NodeType.alternation, splitAlternatives(start, end, result.ast), undefined, start, end),
        ],
    };
};
/*
 * cucumber-expression :=  ( alternation | optional | parameter | text )*
 */
const parseCucumberExpression = parseBetween(NodeType.expression, TokenType.startOfLine, TokenType.endOfLine, [parseAlternation, parseOptional, parseParameter, parseText]);
export default class CucumberExpressionParser {
    parse(expression) {
        const tokenizer = new CucumberExpressionTokenizer();
        const tokens = tokenizer.tokenize(expression);
        const result = parseCucumberExpression(expression, tokens, 0);
        return result.ast[0];
    }
}
function parseBetween(type, beginToken, endToken, parsers) {
    return (expression, tokens, current) => {
        if (!lookingAt(tokens, current, beginToken)) {
            return { consumed: 0, ast: [] };
        }
        let subCurrent = current + 1;
        const result = parseTokensUntil(expression, parsers, tokens, subCurrent, [
            endToken,
            TokenType.endOfLine,
        ]);
        subCurrent += result.consumed;
        // endToken not found
        if (!lookingAt(tokens, subCurrent, endToken)) {
            throw createMissingEndToken(expression, beginToken, endToken, tokens[current]);
        }
        // consumes endToken
        const start = tokens[current].start;
        const end = tokens[subCurrent].end;
        const consumed = subCurrent + 1 - current;
        const ast = [new Node(type, result.ast, undefined, start, end)];
        return { consumed, ast };
    };
}
function parseToken(expression, parsers, tokens, startAt) {
    for (let i = 0; i < parsers.length; i++) {
        const parse = parsers[i];
        const result = parse(expression, tokens, startAt);
        if (result.consumed != 0) {
            return result;
        }
    }
    // If configured correctly this will never happen
    throw new Error('No eligible parsers for ' + tokens);
}
function parseTokensUntil(expression, parsers, tokens, startAt, endTokens) {
    let current = startAt;
    const size = tokens.length;
    const ast = [];
    while (current < size) {
        if (lookingAtAny(tokens, current, endTokens)) {
            break;
        }
        const result = parseToken(expression, parsers, tokens, current);
        if (result.consumed == 0) {
            // If configured correctly this will never happen
            // Keep to avoid infinite loops
            throw new Error('No eligible parsers for ' + tokens);
        }
        current += result.consumed;
        ast.push(...result.ast);
    }
    return { consumed: current - startAt, ast };
}
function lookingAtAny(tokens, at, tokenTypes) {
    return tokenTypes.some((tokenType) => lookingAt(tokens, at, tokenType));
}
function lookingAt(tokens, at, token) {
    if (at < 0) {
        // If configured correctly this will never happen
        // Keep for completeness
        return token == TokenType.startOfLine;
    }
    if (at >= tokens.length) {
        return token == TokenType.endOfLine;
    }
    return tokens[at].type == token;
}
function splitAlternatives(start, end, alternation) {
    const separators = [];
    const alternatives = [];
    let alternative = [];
    alternation.forEach((n) => {
        if (NodeType.alternative == n.type) {
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
    const nodes = [];
    for (let i = 0; i < alternatives.length; i++) {
        const n = alternatives[i];
        if (i == 0) {
            const rightSeparator = separators[i];
            nodes.push(new Node(NodeType.alternative, n, undefined, start, rightSeparator.start));
        }
        else if (i == alternatives.length - 1) {
            const leftSeparator = separators[i - 1];
            nodes.push(new Node(NodeType.alternative, n, undefined, leftSeparator.end, end));
        }
        else {
            const leftSeparator = separators[i - 1];
            const rightSeparator = separators[i];
            nodes.push(new Node(NodeType.alternative, n, undefined, leftSeparator.end, rightSeparator.start));
        }
    }
    return nodes;
}
//# sourceMappingURL=CucumberExpressionParser.js.map