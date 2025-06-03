import * as messages from '@cucumber/messages'
import IGherkinDocument = messages.GherkinDocument

const pickleStepTypeFromKeyword: { [key in messages.StepKeywordType]: messages.PickleStepType } = {
    [messages.StepKeywordType.UNKNOWN]: messages.PickleStepType.UNKNOWN,
    [messages.StepKeywordType.CONTEXT]: messages.PickleStepType.CONTEXT,
    [messages.StepKeywordType.ACTION]: messages.PickleStepType.ACTION,
    [messages.StepKeywordType.OUTCOME]: messages.PickleStepType.OUTCOME,
    [messages.StepKeywordType.CONJUNCTION]: null
}

export default function compile(
  gherkinDocument: IGherkinDocument,
  uri: string,
  newId: messages.IdGenerator.NewId
): readonly messages.Pickle[] {
  const pickles: messages.Pickle[] = []

  if (gherkinDocument.feature == null) {
    return pickles
  }

  const feature = gherkinDocument.feature
  const language = feature.language
  const featureTags = feature.tags
  let featureBackgroundSteps: messages.Step[] = []

  feature.children.forEach((stepsContainer) => {
    if (stepsContainer.background) {
      featureBackgroundSteps = [].concat(stepsContainer.background.steps)
    } else if (stepsContainer.rule) {
      compileRule(
        featureTags,
        featureBackgroundSteps,
        stepsContainer.rule,
        language,
        pickles,
        uri,
        newId
      )
    } else if (stepsContainer.scenario.examples.length === 0) {
      compileScenario(
        featureTags,
        featureBackgroundSteps,
        stepsContainer.scenario,
        language,
        pickles,
        uri,
        newId
      )
    } else {
      compileScenarioOutline(
        featureTags,
        featureBackgroundSteps,
        stepsContainer.scenario,
        language,
        pickles,
        uri,
        newId
      )
    }
  })
  return pickles
}

function compileRule(
  featureTags: readonly messages.Tag[],
  featureBackgroundSteps: readonly messages.Step[],
  rule: messages.Rule,
  language: string,
  pickles: messages.Pickle[],
  uri: string,
  newId: messages.IdGenerator.NewId
) {
  let ruleBackgroundSteps = [].concat(featureBackgroundSteps)

  const tags = [].concat(featureTags).concat(rule.tags)

  rule.children.forEach((stepsContainer) => {
    if (stepsContainer.background) {
      ruleBackgroundSteps = ruleBackgroundSteps.concat(stepsContainer.background.steps)
    } else if (stepsContainer.scenario.examples.length === 0) {
      compileScenario(
        tags,
        ruleBackgroundSteps,
        stepsContainer.scenario,
        language,
        pickles,
        uri,
        newId
      )
    } else {
      compileScenarioOutline(
        tags,
        ruleBackgroundSteps,
        stepsContainer.scenario,
        language,
        pickles,
        uri,
        newId
      )
    }
  })
}

function compileScenario(
  inheritedTags: readonly messages.Tag[],
  backgroundSteps: readonly messages.Step[],
  scenario: messages.Scenario,
  language: string,
  pickles: messages.Pickle[],
  uri: string,
  newId: messages.IdGenerator.NewId
) {
  let lastKeywordType = messages.StepKeywordType.UNKNOWN
  const steps = [] as messages.PickleStep[]

  if (scenario.steps.length !== 0) {
    backgroundSteps.forEach((step) => {
       lastKeywordType = (step.keywordType === messages.StepKeywordType.CONJUNCTION) ?
         lastKeywordType : step.keywordType
       steps.push(pickleStep(step, [], null, newId, lastKeywordType))
    })
  }

  const tags = [].concat(inheritedTags).concat(scenario.tags)

  scenario.steps.forEach((step) => {
    lastKeywordType = (step.keywordType === messages.StepKeywordType.CONJUNCTION) ?
      lastKeywordType : step.keywordType
     steps.push(pickleStep(step, [], null, newId, lastKeywordType))
  })

  const pickle: messages.Pickle = {
    id: newId(),
    uri,
    astNodeIds: [scenario.id],
    tags: pickleTags(tags),
    name: scenario.name,
    language,
    steps,
  }
  pickles.push(pickle)
}

function compileScenarioOutline(
  inheritedTags: readonly messages.Tag[],
  backgroundSteps: readonly messages.Step[],
  scenario: messages.Scenario,
  language: string,
  pickles: messages.Pickle[],
  uri: string,
  newId: messages.IdGenerator.NewId
) {
  scenario.examples
    .filter((e) => e.tableHeader)
    .forEach((examples) => {
      const variableCells = examples.tableHeader.cells
      examples.tableBody.forEach((valuesRow) => {
        let lastKeywordType = messages.StepKeywordType.UNKNOWN
        const steps = [] as messages.PickleStep[]
        if (scenario.steps.length !== 0) {
          backgroundSteps.forEach((step) => {
            lastKeywordType = (step.keywordType === messages.StepKeywordType.CONJUNCTION) ?
              lastKeywordType : step.keywordType
            steps.push(pickleStep(step, [], null, newId, lastKeywordType))
          })
        }

        scenario.steps.forEach((scenarioOutlineStep) => {
          lastKeywordType = (scenarioOutlineStep.keywordType === messages.StepKeywordType.CONJUNCTION) ?
            lastKeywordType : scenarioOutlineStep.keywordType
          const step = pickleStep(scenarioOutlineStep, variableCells, valuesRow, newId, lastKeywordType)
          steps.push(step)
        })

        const id = newId()
        const tags = pickleTags(
          [].concat(inheritedTags).concat(scenario.tags).concat(examples.tags)
        )

        pickles.push({
          id,
          uri,
          astNodeIds: [scenario.id, valuesRow.id],
          name: interpolate(scenario.name, variableCells, valuesRow.cells),
          language,
          steps,
          tags,
        })
      })
    })
}

function createPickleArguments(
  step: messages.Step,
  variableCells: readonly messages.TableCell[],
  valueCells: readonly messages.TableCell[]
): messages.PickleStepArgument | undefined {
  if (step.dataTable) {
    const argument = step.dataTable
    const table: messages.PickleTable = {
      rows: argument.rows.map((row) => {
        return {
          cells: row.cells.map((cell) => {
            return {
              value: interpolate(cell.value, variableCells, valueCells),
            }
          }),
        }
      }),
    }
    return { dataTable: table }
  } else if (step.docString) {
    const argument = step.docString
    const docString: messages.PickleDocString = {
      content: interpolate(argument.content, variableCells, valueCells),
    }
    if (argument.mediaType) {
      docString.mediaType = interpolate(argument.mediaType, variableCells, valueCells)
    }
    return { docString }
  }
}

function interpolate(
  name: string,
  variableCells: readonly messages.TableCell[],
  valueCells: readonly messages.TableCell[]
) {
  variableCells.forEach((variableCell, n) => {
    const valueCell = valueCells[n]
    const valuePattern = '<' + variableCell.value + '>'
    const escapedPattern = valuePattern.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')
    const regexp = new RegExp(escapedPattern, 'g')
    // JS Specific - dollar sign needs to be escaped with another dollar sign
    // https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/replace#Specifying_a_string_as_a_parameter
    const replacement = valueCell.value.replace(new RegExp('\\$', 'g'), '$$$$')
    name = name.replace(regexp, replacement)
  })
  return name
}

function pickleStep(
  step: messages.Step,
  variableCells: readonly messages.TableCell[],
  valuesRow: messages.TableRow | null,
  newId: messages.IdGenerator.NewId,
  keywordType: messages.StepKeywordType
): messages.PickleStep {
  const astNodeIds = [step.id]
  if (valuesRow) {
    astNodeIds.push(valuesRow.id)
  }
  const valueCells = valuesRow ? valuesRow.cells : []

  return {
    id: newId(),
    text: interpolate(step.text, variableCells, valueCells),
    type: pickleStepTypeFromKeyword[keywordType],
    argument: createPickleArguments(step, variableCells, valueCells),
    astNodeIds: astNodeIds,
  }
}

function pickleTags(tags: messages.Tag[]): readonly messages.PickleTag[] {
  return tags.map(pickleTag)
}

function pickleTag(tag: messages.Tag): messages.PickleTag {
  return {
    name: tag.name,
    astNodeId: tag.id,
  }
}
