import * as messages from '@cucumber/messages'

export interface IGherkinLine {
  readonly lineNumber: number
  readonly isEmpty: boolean
  readonly indent?: number
  readonly trimmedLineText: string

  getTableCells(): readonly Item[]

  startsWith(prefix: string): boolean

  getRestTrimmed(length: number): string

  getLineText(number: number): string

  startsWithTitleKeyword(keyword: string): boolean
}

// Represents tags or table cells
export type Item = {
  column: number
  text: string
}

export default interface IToken<TokenType> {
  location: messages.Location
  line: IGherkinLine

  isEof: boolean
  matchedText?: string
  matchedType: TokenType
  matchedItems: readonly Item[]
  matchedKeyword: string
  matchedKeywordType: messages.StepKeywordType
  matchedIndent: number
  matchedGherkinDialect: string
  getTokenValue(): string
  detach(): void
}
