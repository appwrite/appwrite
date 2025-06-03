import { Token, TokenType } from './Ast.js';
import { createCantEscaped, createTheEndOfLIneCanNotBeEscaped } from './Errors.js';
export default class CucumberExpressionTokenizer {
    tokenize(expression) {
        const codePoints = Array.from(expression);
        const tokens = [];
        let buffer = [];
        let previousTokenType = TokenType.startOfLine;
        let treatAsText = false;
        let escaped = 0;
        let bufferStartIndex = 0;
        function convertBufferToToken(tokenType) {
            let escapeTokens = 0;
            if (tokenType == TokenType.text) {
                escapeTokens = escaped;
                escaped = 0;
            }
            const consumedIndex = bufferStartIndex + buffer.length + escapeTokens;
            const t = new Token(tokenType, buffer.join(''), bufferStartIndex, consumedIndex);
            buffer = [];
            bufferStartIndex = consumedIndex;
            return t;
        }
        function tokenTypeOf(codePoint, treatAsText) {
            if (!treatAsText) {
                return Token.typeOf(codePoint);
            }
            if (Token.canEscape(codePoint)) {
                return TokenType.text;
            }
            throw createCantEscaped(expression, bufferStartIndex + buffer.length + escaped);
        }
        function shouldCreateNewToken(previousTokenType, currentTokenType) {
            if (currentTokenType != previousTokenType) {
                return true;
            }
            return currentTokenType != TokenType.whiteSpace && currentTokenType != TokenType.text;
        }
        if (codePoints.length == 0) {
            tokens.push(new Token(TokenType.startOfLine, '', 0, 0));
        }
        codePoints.forEach((codePoint) => {
            if (!treatAsText && Token.isEscapeCharacter(codePoint)) {
                escaped++;
                treatAsText = true;
                return;
            }
            const currentTokenType = tokenTypeOf(codePoint, treatAsText);
            treatAsText = false;
            if (shouldCreateNewToken(previousTokenType, currentTokenType)) {
                const token = convertBufferToToken(previousTokenType);
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
            const token = convertBufferToToken(previousTokenType);
            tokens.push(token);
        }
        if (treatAsText) {
            throw createTheEndOfLIneCanNotBeEscaped(expression);
        }
        tokens.push(new Token(TokenType.endOfLine, '', codePoints.length, codePoints.length));
        return tokens;
    }
}
//# sourceMappingURL=CucumberExpressionTokenizer.js.map