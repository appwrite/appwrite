import DIALECTS from './gherkin-languages.json'
import Dialect from './Dialect'
import { NoSuchLanguageException, ParserException } from './Errors'
import IToken, { IGherkinLine, Item } from './IToken'
import * as messages from '@cucumber/messages'
import { TokenType } from './Parser'
import ITokenMatcher from './ITokenMatcher'
import countSymbols from './countSymbols'

const DIALECT_DICT: { [key: string]: Dialect } = DIALECTS
const LANGUAGE_PATTERN = /^\s*#\s*language\s*:\s*([a-zA-Z\-_]+)\s*$/

function addKeywordTypeMappings(h: { [key: string]: messages.StepKeywordType[] }, keywords: readonly string[], keywordType: messages.StepKeywordType) {
  for (const k of keywords) {
    if (!(k in h)) {
      h[k] = [] as messages.StepKeywordType[]
    }
    h[k].push(keywordType)
  }
}

export default class GherkinClassicTokenMatcher implements ITokenMatcher<TokenType> {
  private dialect: Dialect
  private dialectName: string
  private activeDocStringSeparator: string
  private indentToRemove: number
  private keywordTypesMap: { [key: string]: messages.StepKeywordType[] }

  constructor(private readonly defaultDialectName: string = 'en') {
    this.reset()
  }

  changeDialect(newDialectName: string, location?: messages.Location) {
    const newDialect = DIALECT_DICT[newDialectName]
    if (!newDialect) {
      throw NoSuchLanguageException.create(newDialectName, location)
    }

    this.dialectName = newDialectName
    this.dialect = newDialect
    this.initializeKeywordTypes()
  }

  reset() {
    if (this.dialectName !== this.defaultDialectName) {
      this.changeDialect(this.defaultDialectName)
    }
    this.activeDocStringSeparator = null
    this.indentToRemove = 0
  }

  initializeKeywordTypes() {
    this.keywordTypesMap = {}
    addKeywordTypeMappings(this.keywordTypesMap, this.dialect.given, messages.StepKeywordType.CONTEXT)
    addKeywordTypeMappings(this.keywordTypesMap, this.dialect.when, messages.StepKeywordType.ACTION)
    addKeywordTypeMappings(this.keywordTypesMap, this.dialect.then, messages.StepKeywordType.OUTCOME)
    addKeywordTypeMappings(this.keywordTypesMap,
                           [].concat(this.dialect.and).concat(this.dialect.but),
                           messages.StepKeywordType.CONJUNCTION)
  }

  match_TagLine(token: IToken<TokenType>) {
    if (token.line.startsWith('@')) {
      this.setTokenMatched(token, TokenType.TagLine, null, null, null, null, this.getTags(token.line))
      return true
    }
    return false
  }

  match_FeatureLine(token: IToken<TokenType>) {
    return this.matchTitleLine(token, TokenType.FeatureLine, this.dialect.feature)
  }

  match_ScenarioLine(token: IToken<TokenType>) {
    return (
      this.matchTitleLine(token, TokenType.ScenarioLine, this.dialect.scenario) ||
      this.matchTitleLine(token, TokenType.ScenarioLine, this.dialect.scenarioOutline)
    )
  }

  match_BackgroundLine(token: IToken<TokenType>) {
    return this.matchTitleLine(token, TokenType.BackgroundLine, this.dialect.background)
  }

  match_ExamplesLine(token: IToken<TokenType>) {
    return this.matchTitleLine(token, TokenType.ExamplesLine, this.dialect.examples)
  }

  match_RuleLine(token: IToken<TokenType>) {
    return this.matchTitleLine(token, TokenType.RuleLine, this.dialect.rule)
  }

  match_TableRow(token: IToken<TokenType>) {
    if (token.line.startsWith('|')) {
      // TODO: indent
      this.setTokenMatched(token, TokenType.TableRow, null, null, null, null, token.line.getTableCells())
      return true
    }
    return false
  }

  match_Empty(token: IToken<TokenType>) {
    if (token.line.isEmpty) {
      this.setTokenMatched(token, TokenType.Empty, null, null, 0)
      return true
    }
    return false
  }

  match_Comment(token: IToken<TokenType>) {
    if (token.line.startsWith('#')) {
      const text = token.line.getLineText(0) // take the entire line, including leading space
      this.setTokenMatched(token, TokenType.Comment, text, null, 0)
      return true
    }
    return false
  }

  match_Language(token: IToken<TokenType>) {
    const match = token.line.trimmedLineText.match(LANGUAGE_PATTERN)
    if (match) {
      const newDialectName = match[1]
      this.setTokenMatched(token, TokenType.Language, newDialectName)

      this.changeDialect(newDialectName, token.location)
      return true
    }
    return false
  }

  match_DocStringSeparator(token: IToken<TokenType>) {
    return this.activeDocStringSeparator == null
      ? // open
        this._match_DocStringSeparator(token, '"""', true) ||
          this._match_DocStringSeparator(token, '```', true)
      : // close
        this._match_DocStringSeparator(token, this.activeDocStringSeparator, false)
  }

  public _match_DocStringSeparator(token: IToken<TokenType>, separator: string, isOpen: boolean) {
    if (token.line.startsWith(separator)) {
      let mediaType = null
      if (isOpen) {
        mediaType = token.line.getRestTrimmed(separator.length)
        this.activeDocStringSeparator = separator
        this.indentToRemove = token.line.indent
      } else {
        this.activeDocStringSeparator = null
        this.indentToRemove = 0
      }

      this.setTokenMatched(token, TokenType.DocStringSeparator, mediaType, separator)
      return true
    }
    return false
  }

  match_EOF(token: IToken<TokenType>) {
    if (token.isEof) {
      this.setTokenMatched(token, TokenType.EOF)
      return true
    }
    return false
  }

  match_StepLine(token: IToken<TokenType>) {
    const keywords = []
      .concat(this.dialect.given)
      .concat(this.dialect.when)
      .concat(this.dialect.then)
      .concat(this.dialect.and)
      .concat(this.dialect.but)
    for (const keyword of keywords) {
      if (token.line.startsWith(keyword)) {
        const title = token.line.getRestTrimmed(keyword.length)
        const keywordTypes = this.keywordTypesMap[keyword]
        let keywordType = keywordTypes[0]
        if (keywordTypes.length > 1) {
          keywordType = messages.StepKeywordType.UNKNOWN
        }

        this.setTokenMatched(token, TokenType.StepLine, title, keyword, null, keywordType)
        return true
      }
    }

    return false
  }

  match_Other(token: IToken<TokenType>) {
    const text = token.line.getLineText(this.indentToRemove) // take the entire line, except removing DocString indents
    this.setTokenMatched(token, TokenType.Other, this.unescapeDocString(text), null, 0)
    return true
  }

  getTags(line: IGherkinLine): readonly Item[] {
    const uncommentedLine = line.trimmedLineText.split(/\s#/g, 2)[0]
    let column = line.indent + 1
    const items = uncommentedLine.split('@')
    const tags: any[] = []
    for (let i = 0; i < items.length; i++) {
      const item = items[i].trimRight()
      if (item.length == 0) {
        continue
      }
      if (!item.match(/^\S+$/)) {
        throw ParserException.create('A tag may not contain whitespace', line.lineNumber, column)
      }
      const span = { column, text: '@' + item }
      tags.push(span)
      column += countSymbols(items[i]) + 1
    }
    return tags
  }

  private matchTitleLine(
    token: IToken<TokenType>,
    tokenType: TokenType,
    keywords: readonly string[]
  ): boolean {
    for (const keyword of keywords) {
      if (token.line.startsWithTitleKeyword(keyword)) {
        const title = token.line.getRestTrimmed(keyword.length + ':'.length)
        this.setTokenMatched(token, tokenType, title, keyword)
        return true
      }
    }
    return false
  }

  private setTokenMatched(
    token: IToken<TokenType>,
    matchedType: TokenType,
    text?: string,
    keyword?: string,
    indent?: number,
    keywordType?: messages.StepKeywordType,
    items?: readonly Item[]
  ) {
    token.matchedType = matchedType
    token.matchedText = text
    token.matchedKeyword = keyword
    token.matchedKeywordType = keywordType
    token.matchedIndent =
      typeof indent === 'number' ? indent : token.line == null ? 0 : token.line.indent
    token.matchedItems = items || []

    token.location.column = token.matchedIndent + 1
    token.matchedGherkinDialect = this.dialectName
  }

  private unescapeDocString(text: string) {
    if (this.activeDocStringSeparator === '"""') {
      return text.replace('\\"\\"\\"', '"""')
    }
    if (this.activeDocStringSeparator === '```') {
      return text.replace('\\`\\`\\`', '```')
    }
    return text
  }
}
