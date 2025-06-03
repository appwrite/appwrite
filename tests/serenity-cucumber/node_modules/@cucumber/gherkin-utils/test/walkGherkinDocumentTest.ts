import assert from 'assert'
import parse from './parse'
import { GherkinDocumentHandlers, walkGherkinDocument } from '../src'

describe('walkGherkinDocument', () => {
  it('traverses depth first', () => {
    const gherkinDocument = parse(`
      @A
      Feature: B
        Background: C

        @D
        Scenario: E
          Given F

        Scenario: G
          Given H

        Rule: I
          @J
          Scenario: K
            Given L
              | M | N |
              | O | P |

            Examples: Q

          Scenario: R
            Given S
              """
              T
              """

            Examples: U
              | V |
              | W |
`)

    const handlers: GherkinDocumentHandlers<string[]> = {
      comment: (comment, acc) => acc,
      dataTable: (dataTable, acc) => acc,
      docString: (docString, acc) => acc.concat(docString.content),
      tableCell: (tableCell, acc) => acc.concat(tableCell.value),
      tableRow: (tableRow, acc) => acc,
      tag: (tag, acc) => acc.concat(tag.name.substring(1)),
      feature: (feature, acc) => acc.concat(feature.name),
      background: (background, acc) => acc.concat(background.name),
      rule: (rule, acc) => acc.concat(rule.name),
      scenario: (scenario, acc) => acc.concat(scenario.name),
      examples: (examples, acc) => acc.concat(examples.name),
      step: (step, acc) => acc.concat(step.text),
    }

    const names = walkGherkinDocument<string[]>(gherkinDocument, [], handlers)
    assert.deepEqual(names, 'A B C D E F G H I J K L M N O P Q R S T U V W'.split(' '))
  })
})
