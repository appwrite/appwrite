import IToken from './IToken';
import { GherkinException } from './Errors';
export declare class UnexpectedTokenException extends GherkinException {
    static create<TokenType>(token: IToken<TokenType>, expectedTokenTypes: string[]): GherkinException;
}
export declare class UnexpectedEOFException extends GherkinException {
    static create<TokenType>(token: IToken<TokenType>, expectedTokenTypes: string[]): GherkinException;
}
//# sourceMappingURL=TokenExceptions.d.ts.map