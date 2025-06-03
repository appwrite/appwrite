import * as messages from '@cucumber/messages'
import {
  AstBuilder,
  Parser,
  GherkinClassicTokenMatcher,
  GherkinInMarkdownTokenMatcher,
} from '@cucumber/gherkin'

export default function parse(
  source: string,
  tokenMatcher:
    | GherkinClassicTokenMatcher
    | GherkinInMarkdownTokenMatcher = new GherkinClassicTokenMatcher()
): messages.GherkinDocument {
  const newId = messages.IdGenerator.uuid()
  const parser = new Parser(new AstBuilder(newId), tokenMatcher)
  try {
    const gherkinDocument = parser.parse(source)
    gherkinDocument.uri = ''
    return gherkinDocument
  } catch (err) {
    err.message += `\n${source}`
    throw err
  }
}
