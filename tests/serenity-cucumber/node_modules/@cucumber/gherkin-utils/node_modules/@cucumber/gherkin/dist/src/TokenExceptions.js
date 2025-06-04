"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.UnexpectedEOFException = exports.UnexpectedTokenException = void 0;
const Errors_1 = require("./Errors");
class UnexpectedTokenException extends Errors_1.GherkinException {
    static create(token, expectedTokenTypes) {
        const message = `expected: ${expectedTokenTypes.join(', ')}, got '${token
            .getTokenValue()
            .trim()}'`;
        const location = tokenLocation(token);
        return this._create(message, location);
    }
}
exports.UnexpectedTokenException = UnexpectedTokenException;
class UnexpectedEOFException extends Errors_1.GherkinException {
    static create(token, expectedTokenTypes) {
        const message = `unexpected end of file, expected: ${expectedTokenTypes.join(', ')}`;
        const location = tokenLocation(token);
        return this._create(message, location);
    }
}
exports.UnexpectedEOFException = UnexpectedEOFException;
function tokenLocation(token) {
    return token.location && token.location.line && token.line && token.line.indent !== undefined
        ? {
            line: token.location.line,
            column: token.line.indent + 1,
        }
        : token.location;
}
//# sourceMappingURL=TokenExceptions.js.map