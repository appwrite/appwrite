"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
var Ast_js_1 = require("./Ast.js");
var Errors_js_1 = require("./Errors.js");
var CucumberExpressionTokenizer = /** @class */ (function () {
    function CucumberExpressionTokenizer() {
    }
    CucumberExpressionTokenizer.prototype.tokenize = function (expression) {
        var codePoints = Array.from(expression);
        var tokens = [];
        var buffer = [];
        var previousTokenType = Ast_js_1.TokenType.startOfLine;
        var treatAsText = false;
        var escaped = 0;
        var bufferStartIndex = 0;
        function convertBufferToToken(tokenType) {
            var escapeTokens = 0;
            if (tokenType == Ast_js_1.TokenType.text) {
                escapeTokens = escaped;
                escaped = 0;
            }
            var consumedIndex = bufferStartIndex + buffer.length + escapeTokens;
            var t = new Ast_js_1.Token(tokenType, buffer.join(''), bufferStartIndex, consumedIndex);
            buffer = [];
            bufferStartIndex = consumedIndex;
            return t;
        }
        function tokenTypeOf(codePoint, treatAsText) {
            if (!treatAsText) {
                return Ast_js_1.Token.typeOf(codePoint);
            }
            if (Ast_js_1.Token.canEscape(codePoint)) {
                return Ast_js_1.TokenType.text;
            }
            throw (0, Errors_js_1.createCantEscaped)(expression, bufferStartIndex + buffer.length + escaped);
        }
        function shouldCreateNewToken(previousTokenType, currentTokenType) {
            if (currentTokenType != previousTokenType) {
                return true;
            }
            return currentTokenType != Ast_js_1.TokenType.whiteSpace && currentTokenType != Ast_js_1.TokenType.text;
        }
        if (codePoints.length == 0) {
            tokens.push(new Ast_js_1.Token(Ast_js_1.TokenType.startOfLine, '', 0, 0));
        }
        codePoints.forEach(function (codePoint) {
            if (!treatAsText && Ast_js_1.Token.isEscapeCharacter(codePoint)) {
                escaped++;
                treatAsText = true;
                return;
            }
            var currentTokenType = tokenTypeOf(codePoint, treatAsText);
            treatAsText = false;
            if (shouldCreateNewToken(previousTokenType, currentTokenType)) {
                var token = convertBufferToToken(previousTokenType);
                previousTokenType = currentTokenType;
                buffer.push(codePoint);
                tokens.push(token);
            }
            else {
                previousTokenType = currentTokenType;
                buffer.push(codePoint);
            }
        });
        if (buffer.length > 0) {
            var token = convertBufferToToken(previousTokenType);
            tokens.push(token);
        }
        if (treatAsText) {
            throw (0, Errors_js_1.createTheEndOfLIneCanNotBeEscaped)(expression);
        }
        tokens.push(new Ast_js_1.Token(Ast_js_1.TokenType.endOfLine, '', codePoints.length, codePoints.length));
        return tokens;
    };
    return CucumberExpressionTokenizer;
}());
exports.default = CucumberExpressionTokenizer;
//# sourceMappingURL=CucumberExpressionTokenizer.js.map