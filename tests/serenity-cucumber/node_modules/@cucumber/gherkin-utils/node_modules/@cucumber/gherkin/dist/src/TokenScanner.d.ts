import IToken from './IToken';
import * as messages from '@cucumber/messages';
/**
 * The scanner reads a gherkin doc (typically read from a .feature file) and creates a token for each line.
 * The tokens are passed to the parser, which outputs an AST (Abstract Syntax Tree).
 *
 * If the scanner sees a `#` language header, it will reconfigure itself dynamically to look for
 * Gherkin keywords for the associated language. The keywords are defined in gherkin-languages.json.
 */
export default class TokenScanner<TokenType> {
    private readonly makeToken;
    private lineNumber;
    private lines;
    constructor(source: string, makeToken: (line: string, location: messages.Location) => IToken<TokenType>);
    read(): IToken<TokenType>;
}
//# sourceMappingURL=TokenScanner.d.ts.map