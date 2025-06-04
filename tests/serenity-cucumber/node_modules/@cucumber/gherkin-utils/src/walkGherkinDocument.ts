import * as messages from '@cucumber/messages'
import { GherkinDocumentHandlers } from './GherkinDocumentHandlers'

/**
 * Walks a Gherkin Document, visiting each node depth first (in the order they appear in the source)
 *
 * @param gherkinDocument
 * @param initialValue the initial value of the traversal
 * @param handlers handlers for each node type, which may return a new value
 * @return result the final value
 */
export function walkGherkinDocument<Acc>(
  gherkinDocument: messages.GherkinDocument,
  initialValue: Acc,
  handlers: Partial<GherkinDocumentHandlers<Acc>>
): Acc {
  let acc = initialValue
  const h: GherkinDocumentHandlers<Acc> = { ...makeDefaultHandlers<Acc>(), ...handlers }
  const feature = gherkinDocument.feature
  if (!feature) return acc
  acc = walkTags(feature.tags || [], acc)
  acc = h.feature(feature, acc)

  for (const child of feature.children) {
    if (child.background) {
      acc = walkStepContainer(child.background, acc)
    } else if (child.scenario) {
      acc = walkStepContainer(child.scenario, acc)
    } else if (child.rule) {
      acc = walkTags(child.rule.tags || [], acc)
      acc = h.rule(child.rule, acc)
      for (const ruleChild of child.rule.children) {
        if (ruleChild.background) {
          acc = walkStepContainer(ruleChild.background, acc)
        } else if (ruleChild.scenario) {
          acc = walkStepContainer(ruleChild.scenario, acc)
        }
      }
    }
  }
  return acc

  function walkTags(tags: readonly messages.Tag[], acc: Acc): Acc {
    return tags.reduce((acc, tag) => h.tag(tag, acc), acc)
  }

  function walkSteps(steps: readonly messages.Step[], acc: Acc): Acc {
    return steps.reduce((acc, step) => walkStep(step, acc), acc)
  }

  function walkStep(step: messages.Step, acc: Acc): Acc {
    acc = h.step(step, acc)
    if (step.docString) {
      acc = h.docString(step.docString, acc)
    }
    if (step.dataTable) {
      acc = h.dataTable(step.dataTable, acc)
      acc = walkTableRows(step.dataTable.rows, acc)
    }
    return acc
  }

  function walkTableRows(tableRows: readonly messages.TableRow[], acc: Acc): Acc {
    return tableRows.reduce((acc, tableRow) => walkTableRow(tableRow, acc), acc)
  }

  function walkTableRow(tableRow: messages.TableRow, acc: Acc): Acc {
    acc = h.tableRow(tableRow, acc)
    return tableRow.cells.reduce((acc, tableCell) => h.tableCell(tableCell, acc), acc)
  }

  function walkStepContainer(
    stepContainer: messages.Scenario | messages.Background,
    acc: Acc
  ): Acc {
    const scenario: messages.Scenario = 'tags' in stepContainer ? stepContainer : null
    acc = walkTags(scenario?.tags || [], acc)
    acc = scenario
      ? h.scenario(scenario, acc)
      : h.background(stepContainer as messages.Background, acc)
    acc = walkSteps(stepContainer.steps, acc)

    if (scenario) {
      for (const examples of scenario.examples || []) {
        acc = walkTags(examples.tags || [], acc)
        acc = h.examples(examples, acc)
        if (examples.tableHeader) {
          acc = walkTableRow(examples.tableHeader, acc)
          acc = walkTableRows(examples.tableBody || [], acc)
        }
      }
    }
    return acc
  }
}

function makeDefaultHandlers<Acc>() {
  const defaultHandlers: GherkinDocumentHandlers<Acc> = {
    feature(feature, acc) {
      return acc
    },
    background(background, acc) {
      return acc
    },
    rule(rule, acc) {
      return acc
    },
    scenario(scenario, acc) {
      return acc
    },
    step(step, acc) {
      return acc
    },
    examples(examples, acc) {
      return acc
    },
    tag(tag, acc) {
      return acc
    },
    comment(comment, acc) {
      return acc
    },
    dataTable(dataTable, acc) {
      return acc
    },
    tableRow(tableRow, acc) {
      return acc
    },
    tableCell(tableCell, acc) {
      return acc
    },
    docString(docString, acc) {
      return acc
    },
  }
  return defaultHandlers
}
