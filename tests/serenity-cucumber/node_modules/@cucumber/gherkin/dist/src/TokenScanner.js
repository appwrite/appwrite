"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
/**
 * The scanner reads a gherkin doc (typically read from a .feature file) and creates a token for each line.
 * The tokens are passed to the parser, which outputs an AST (Abstract Syntax Tree).
 *
 * If the scanner sees a `#` language header, it will reconfigure itself dynamically to look for
 * Gherkin keywords for the associated language. The keywords are defined in gherkin-languages.json.
 */
class TokenScanner {
    constructor(source, makeToken) {
        this.makeToken = makeToken;
        this.lineNumber = 0;
        this.lines = source.split(/\r?\n/);
        if (this.lines.length > 0 && this.lines[this.lines.length - 1].trim() === '') {
            this.lines.pop();
        }
    }
    read() {
        const line = this.lines[this.lineNumber++];
        const location = {
            line: this.lineNumber,
        };
        return this.makeToken(line, location);
    }
}
exports.default = TokenScanner;
//# sourceMappingURL=TokenScanner.js.map