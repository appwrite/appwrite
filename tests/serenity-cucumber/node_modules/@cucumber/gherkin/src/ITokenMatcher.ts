import * as messages from '@cucumber/messages'
import IToken from './IToken'

export default interface ITokenMatcher<TokenType> {
  changeDialect(newDialectName: string, location?: messages.Location): void

  reset(): void

  match_TagLine(token: IToken<TokenType>): boolean

  match_FeatureLine(token: IToken<TokenType>): boolean

  match_ScenarioLine(token: IToken<TokenType>): boolean

  match_BackgroundLine(token: IToken<TokenType>): boolean

  match_ExamplesLine(token: IToken<TokenType>): boolean

  match_RuleLine(token: IToken<TokenType>): boolean

  match_TableRow(token: IToken<TokenType>): boolean

  match_Empty(token: IToken<TokenType>): boolean

  match_Comment(token: IToken<TokenType>): boolean

  match_Language(token: IToken<TokenType>): boolean

  match_DocStringSeparator(token: IToken<TokenType>): boolean

  match_EOF(token: IToken<TokenType>): boolean

  match_StepLine(token: IToken<TokenType>): boolean

  match_Other(token: IToken<TokenType>): boolean
}
