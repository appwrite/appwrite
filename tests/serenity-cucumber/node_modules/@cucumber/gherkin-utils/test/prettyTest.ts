import assert from 'assert'
import path from 'path'
import parse from './parse'
import pretty, { escapeCell } from '../src/pretty'
import { GherkinClassicTokenMatcher, GherkinInMarkdownTokenMatcher } from '@cucumber/gherkin'
import fg from 'fast-glob'
import fs from 'fs'
import * as messages from '@cucumber/messages'

describe('pretty', () => {
  it('renders an empty file', () => {
    checkGherkinToAstToMarkdownToAstToGherkin('')
  })

  it('renders the language header if it is not "en"', () => {
    checkGherkinToAstToGherkin(`# language: no
Egenskap: hallo
`)
  })

  it('renders a feature with empty scenarios', () => {
    checkGherkinToAstToMarkdownToAstToGherkin(`Feature: hello

  Scenario: one

  Scenario: Two
`)
  })

  it('renders a feature with two scenarios', () => {
    checkGherkinToAstToMarkdownToAstToGherkin(`Feature: hello

  Scenario: one
    Given hello

  Scenario: two
    Given world
`)
  })

  it('renders a feature with two scenarios in a rule', () => {
    checkGherkinToAstToMarkdownToAstToGherkin(`Feature: hello

  Rule: ok

    Scenario: one
      Given hello

    Scenario: two
      Given world
`)
  })

  it('renders a feature with background and scenario', () => {
    checkGherkinToAstToMarkdownToAstToGherkin(`Feature: hello

  Background: bbb
    Given hello

  Scenario: two
    Given world
`)
  })

  it('renders a rule with background and scenario', () => {
    checkGherkinToAstToMarkdownToAstToGherkin(`Feature: hello

  Rule: machin

    Background: bbb
      Given hello

    Scenario: two
      Given world
`)
  })

  it('renders tags when set', () => {
    checkGherkinToAstToMarkdownToAstToGherkin(`@featureTag
Feature: hello

  Rule: machin

    Background: bbb
      Given hello

    @scenarioTag @secondTag
    Scenario: two
      Given world
`)
  })

  it('renders examples tables', () => {
    checkGherkinToAstToMarkdownToAstToGherkin(`Feature: hello

  Scenario: one
    Given a a <text> and a <number>

    Examples: some data
      | text | number |
      | a    |      1 |
      | ab   |     10 |
      | abc  |    100 |
`)
  })

  it('renders data tables', () => {
    checkGherkinToAstToMarkdownToAstToGherkin(`Feature: hello

  Scenario: one
    Given a data table:
      | text | numbers |
      | a    |       1 |
      | ab   |      10 |
      | abc  |     100 |
`)
  })

  describe('DocString', () => {
    it('is rendered with type', () => {
      checkGherkinToAstToMarkdownToAstToGherkin(`Feature: hello

  Scenario: one
    Given a doc string:
      \`\`\`json
      {
        "foo": "bar"
      }
      \`\`\`
`)
    })

    it('escapes DocString separators', () => {
      checkGherkinToAstToMarkdownToAstToGherkin(`Feature: hello

  Scenario: one
    Given a doc string:
      \`\`\`
      2
      \`\`
      3
      \\\`\\\`\\\`
      4
      \\\`\\\`\\\`\`
      5
      \\\`\\\`\\\`\`\`
      \`\`\`
`)
    })
  })

  xit('renders comments', () => {
    checkGherkinToAstToGherkin(`# one
Feature: hello

  Scenario: one
    # two
    Given a doc string:
      """
      a
      \\"\\"\\"
      b
      """
`)
  })

  it('renders descriptions when set', () => {
    checkGherkinToAstToGherkin(`Feature: hello
  So this is a feature

  Rule: machin
    The first rule of the feature states things

    Background: bbb
      We can have some explications for the background

      Given hello

    Scenario: two
      This scenario will do things, maybe

      Given world
`)
  })

  const featureFiles = fg.sync(`${__dirname}/../../../gherkin/testdata/good/*.feature`)
  for (const featureFile of featureFiles) {
    const relativePath = path.relative(__dirname, featureFile)
    it(`renders ${relativePath}`, () => {
      const gherkinSource = fs.readFileSync(featureFile, 'utf-8')
      const gherkinDocument = parse(gherkinSource, new GherkinClassicTokenMatcher())
      const formattedGherkinSource = pretty(gherkinDocument, 'gherkin')
      const language = gherkinDocument.feature?.language || 'en'
      const newGherkinDocument = checkGherkinToAstToGherkin(formattedGherkinSource, language)
      assert(newGherkinDocument)
      // TODO: comments
      if (gherkinDocument.comments.length === 0) {
        assert.deepStrictEqual(neutralize(newGherkinDocument), neutralize(gherkinDocument))
      }
    })
  }

  describe('escapeCell', () => {
    it('escapes nothing', () => {
      assert.strictEqual(escapeCell('hello'), 'hello')
    })

    it('escapes newline', () => {
      assert.strictEqual(escapeCell('\n'), '\\n')
    })

    it('escapes pipe', () => {
      assert.strictEqual(escapeCell('|'), '\\|')
    })

    it('escapes backslash', () => {
      assert.strictEqual(escapeCell('\\'), '\\\\')
    })
  })
})

function checkGherkinToAstToMarkdownToAstToGherkin(gherkinSource: string) {
  const gherkinDocument = parse(gherkinSource, new GherkinClassicTokenMatcher())
  // console.log({gherkinDocument})
  const markdownSource = pretty(gherkinDocument, 'markdown')
  // console.log(`<Markdown>${markdownSource}</Markdown>`)
  const markdownGherkinDocument = parse(markdownSource, new GherkinInMarkdownTokenMatcher())
  // console.log({markdownGherkinDocument})

  const newGherkinSource = pretty(markdownGherkinDocument, 'gherkin')
  // console.log(`<Gherkin>${newGherkinSource}</Gherkin>`)
  assert.strictEqual(newGherkinSource, gherkinSource)
}

function checkGherkinToAstToGherkin(
  gherkinSource: string,
  language = 'en'
): messages.GherkinDocument {
  const gherkinDocument = parse(gherkinSource, new GherkinClassicTokenMatcher(language))
  const newGherkinSource = pretty(gherkinDocument, 'gherkin')
  // console.log(`<Gherkin>${newGherkinSource}</Gherkin>`)
  assert.strictEqual(newGherkinSource, gherkinSource)
  return gherkinDocument
}

function neutralize(gherkinDocument: messages.GherkinDocument): messages.GherkinDocument {
  const json = JSON.stringify(
    gherkinDocument,
    (key, value) => {
      if ('id' === key) {
        return 'id'
      } else if (['column', 'line'].includes(key)) {
        return '0'
      } else {
        return value
      }
    },
    2
  )
  return JSON.parse(json)
}
