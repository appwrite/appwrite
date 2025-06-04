"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.TokenType = exports.Token = exports.NodeType = exports.Node = exports.purposeOf = exports.symbolOf = void 0;
var escapeCharacter = '\\';
var alternationCharacter = '/';
var beginParameterCharacter = '{';
var endParameterCharacter = '}';
var beginOptionalCharacter = '(';
var endOptionalCharacter = ')';
function symbolOf(token) {
    switch (token) {
        case TokenType.beginOptional:
            return beginOptionalCharacter;
        case TokenType.endOptional:
            return endOptionalCharacter;
        case TokenType.beginParameter:
            return beginParameterCharacter;
        case TokenType.endParameter:
            return endParameterCharacter;
        case TokenType.alternation:
            return alternationCharacter;
    }
    return '';
}
exports.symbolOf = symbolOf;
function purposeOf(token) {
    switch (token) {
        case TokenType.beginOptional:
        case TokenType.endOptional:
            return 'optional text';
        case TokenType.beginParameter:
        case TokenType.endParameter:
            return 'a parameter';
        case TokenType.alternation:
            return 'alternation';
    }
    return '';
}
exports.purposeOf = purposeOf;
var Node = /** @class */ (function () {
    function Node(type, nodes, token, start, end) {
        this.type = type;
        this.nodes = nodes;
        this.token = token;
        this.start = start;
        this.end = end;
        if (nodes === undefined && token === undefined) {
            throw new Error('Either nodes or token must be defined');
        }
    }
    Node.prototype.text = function () {
        if (this.nodes && this.nodes.length > 0) {
            return this.nodes.map(function (value) { return value.text(); }).join('');
        }
        return this.token || '';
    };
    return Node;
}());
exports.Node = Node;
var NodeType;
(function (NodeType) {
    NodeType["text"] = "TEXT_NODE";
    NodeType["optional"] = "OPTIONAL_NODE";
    NodeType["alternation"] = "ALTERNATION_NODE";
    NodeType["alternative"] = "ALTERNATIVE_NODE";
    NodeType["parameter"] = "PARAMETER_NODE";
    NodeType["expression"] = "EXPRESSION_NODE";
})(NodeType = exports.NodeType || (exports.NodeType = {}));
var Token = /** @class */ (function () {
    function Token(type, text, start, end) {
        this.type = type;
        this.text = text;
        this.start = start;
        this.end = end;
    }
    Token.isEscapeCharacter = function (codePoint) {
        return codePoint == escapeCharacter;
    };
    Token.canEscape = function (codePoint) {
        if (codePoint == ' ') {
            // TODO: Unicode whitespace?
            return true;
        }
        switch (codePoint) {
            case escapeCharacter:
                return true;
            case alternationCharacter:
                return true;
            case beginParameterCharacter:
                return true;
            case endParameterCharacter:
                return true;
            case beginOptionalCharacter:
                return true;
            case endOptionalCharacter:
                return true;
        }
        return false;
    };
    Token.typeOf = function (codePoint) {
        if (codePoint == ' ') {
            // TODO: Unicode whitespace?
            return TokenType.whiteSpace;
        }
        switch (codePoint) {
            case alternationCharacter:
                return TokenType.alternation;
            case beginParameterCharacter:
                return TokenType.beginParameter;
            case endParameterCharacter:
                return TokenType.endParameter;
            case beginOptionalCharacter:
                return TokenType.beginOptional;
            case endOptionalCharacter:
                return TokenType.endOptional;
        }
        return TokenType.text;
    };
    return Token;
}());
exports.Token = Token;
var TokenType;
(function (TokenType) {
    TokenType["startOfLine"] = "START_OF_LINE";
    TokenType["endOfLine"] = "END_OF_LINE";
    TokenType["whiteSpace"] = "WHITE_SPACE";
    TokenType["beginOptional"] = "BEGIN_OPTIONAL";
    TokenType["endOptional"] = "END_OPTIONAL";
    TokenType["beginParameter"] = "BEGIN_PARAMETER";
    TokenType["endParameter"] = "END_PARAMETER";
    TokenType["alternation"] = "ALTERNATION";
    TokenType["text"] = "TEXT";
})(TokenType = exports.TokenType || (exports.TokenType = {}));
//# sourceMappingURL=Ast.js.map