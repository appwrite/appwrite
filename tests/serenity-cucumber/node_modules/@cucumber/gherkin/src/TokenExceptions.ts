import IToken from './IToken'
import { GherkinException } from './Errors'

export class UnexpectedTokenException extends GherkinException {
  public static create<TokenType>(token: IToken<TokenType>, expectedTokenTypes: string[]) {
    const message = `expected: ${expectedTokenTypes.join(', ')}, got '${token
      .getTokenValue()
      .trim()}'`

    const location = tokenLocation(token)

    return this._create(message, location)
  }
}

export class UnexpectedEOFException extends GherkinException {
  public static create<TokenType>(token: IToken<TokenType>, expectedTokenTypes: string[]) {
    const message = `unexpected end of file, expected: ${expectedTokenTypes.join(', ')}`
    const location = tokenLocation(token)

    return this._create(message, location)
  }
}

function tokenLocation<TokenType>(token: IToken<TokenType>) {
  return token.location && token.location.line && token.line && token.line.indent !== undefined
    ? {
        line: token.location.line,
        column: token.line.indent + 1,
      }
    : token.location
}
