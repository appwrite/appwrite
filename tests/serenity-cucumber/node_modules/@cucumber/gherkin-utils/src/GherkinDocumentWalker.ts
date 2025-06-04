// This file is DEPRECATED - use ./walkGherkinDocument instead
import * as messages from '@cucumber/messages'

export interface IFilters {
  acceptScenario?: (scenario: messages.Scenario) => boolean
  acceptStep?: (step: messages.Step) => boolean
  acceptBackground?: (background: messages.Background) => boolean
  acceptRule?: (rule: messages.Rule) => boolean
  acceptFeature?: (feature: messages.Feature) => boolean
}

export interface IHandlers {
  handleStep?: (step: messages.Step) => void
  handleScenario?: (scenario: messages.Scenario) => void
  handleBackground?: (background: messages.Background) => void
  handleRule?: (rule: messages.Rule) => void
  handleFeature?: (feature: messages.Feature) => void
}

const defaultFilters: IFilters = {
  acceptScenario: () => true,
  acceptStep: () => true,
  acceptBackground: () => true,
  acceptRule: () => true,
  acceptFeature: () => true,
}

export const rejectAllFilters: IFilters = {
  acceptScenario: () => false,
  acceptStep: () => false,
  acceptBackground: () => false,
  acceptRule: () => false,
  acceptFeature: () => false,
}

const defaultHandlers: IHandlers = {
  handleStep: () => null,
  handleScenario: () => null,
  handleBackground: () => null,
  handleRule: () => null,
  handleFeature: () => null,
}

export default class GherkinDocumentWalker {
  private readonly filters: IFilters
  private readonly handlers: IHandlers

  constructor(filters?: IFilters, handlers?: IHandlers) {
    this.filters = { ...defaultFilters, ...filters }
    this.handlers = { ...defaultHandlers, ...handlers }
  }

  public walkGherkinDocument(
    gherkinDocument: messages.GherkinDocument
  ): messages.GherkinDocument | null {
    if (!gherkinDocument.feature) {
      return null
    }

    const feature = this.walkFeature(gherkinDocument.feature)

    if (!feature) {
      return null
    }

    return {
      feature,
      comments: gherkinDocument.comments,
      uri: gherkinDocument.uri,
    }
  }

  protected walkFeature(feature: messages.Feature): messages.Feature {
    const keptChildren = this.walkFeatureChildren(feature.children)

    this.handlers.handleFeature(feature)

    const backgroundKept = keptChildren.find((child) => child.background)

    if (this.filters.acceptFeature(feature) || backgroundKept) {
      return this.copyFeature(
        feature,
        feature.children.map((child) => {
          if (child.background) {
            return {
              background: this.copyBackground(child.background),
            }
          }
          if (child.scenario) {
            return {
              scenario: this.copyScenario(child.scenario),
            }
          }
          if (child.rule) {
            return {
              rule: this.copyRule(child.rule, child.rule.children),
            }
          }
        })
      )
    }

    if (keptChildren.find((child) => child !== null)) {
      return this.copyFeature(feature, keptChildren)
    }
  }

  private copyFeature(
    feature: messages.Feature,
    children: messages.FeatureChild[]
  ): messages.Feature {
    return {
      children: this.filterFeatureChildren(feature, children),
      location: feature.location,
      language: feature.language,
      keyword: feature.keyword,
      name: feature.name,
      description: feature.description,
      tags: this.copyTags(feature.tags),
    }
  }

  private copyTags(tags: readonly messages.Tag[]): messages.Tag[] {
    return tags.map((tag) => ({
      name: tag.name,
      id: tag.id,
      location: tag.location,
    }))
  }

  private filterFeatureChildren(
    feature: messages.Feature,
    children: messages.FeatureChild[]
  ): messages.FeatureChild[] {
    const copyChildren: messages.FeatureChild[] = []

    const scenariosKeptById = new Map(
      children.filter((child) => child.scenario).map((child) => [child.scenario.id, child])
    )

    const ruleKeptById = new Map(
      children.filter((child) => child.rule).map((child) => [child.rule.id, child])
    )

    for (const child of feature.children) {
      if (child.background) {
        copyChildren.push({
          background: this.copyBackground(child.background),
        })
      }

      if (child.scenario) {
        const scenarioCopy = scenariosKeptById.get(child.scenario.id)
        if (scenarioCopy) {
          copyChildren.push(scenarioCopy)
        }
      }

      if (child.rule) {
        const ruleCopy = ruleKeptById.get(child.rule.id)
        if (ruleCopy) {
          copyChildren.push(ruleCopy)
        }
      }
    }
    return copyChildren
  }

  private walkFeatureChildren(children: readonly messages.FeatureChild[]): messages.FeatureChild[] {
    const childrenCopy: messages.FeatureChild[] = []

    for (const child of children) {
      let backgroundCopy: messages.Background = null
      let scenarioCopy: messages.Scenario = null
      let ruleCopy: messages.Rule = null

      if (child.background) {
        backgroundCopy = this.walkBackground(child.background)
      }
      if (child.scenario) {
        scenarioCopy = this.walkScenario(child.scenario)
      }
      if (child.rule) {
        ruleCopy = this.walkRule(child.rule)
      }

      if (backgroundCopy || scenarioCopy || ruleCopy) {
        childrenCopy.push({
          background: backgroundCopy,
          scenario: scenarioCopy,
          rule: ruleCopy,
        })
      }
    }

    return childrenCopy
  }

  protected walkRule(rule: messages.Rule): messages.Rule {
    const children = this.walkRuleChildren(rule.children)

    this.handlers.handleRule(rule)

    const backgroundKept = children.find((child) => child !== null && child.background !== null)
    const scenariosKept = children.filter((child) => child !== null && child.scenario !== null)

    if (this.filters.acceptRule(rule) || backgroundKept) {
      return this.copyRule(rule, rule.children)
    }
    if (scenariosKept.length > 0) {
      return this.copyRule(rule, scenariosKept)
    }
  }

  private copyRule(rule: messages.Rule, children: readonly messages.RuleChild[]): messages.Rule {
    return {
      id: rule.id,
      name: rule.name,
      description: rule.description,
      location: rule.location,
      keyword: rule.keyword,
      children: this.filterRuleChildren(rule.children, children),
      tags: this.copyTags(rule.tags),
    }
  }

  private filterRuleChildren(
    children: readonly messages.RuleChild[],
    childrenKept: readonly messages.RuleChild[]
  ): messages.RuleChild[] {
    const childrenCopy: messages.RuleChild[] = []
    const scenariosKeptIds = childrenKept
      .filter((child) => child.scenario)
      .map((child) => child.scenario.id)

    for (const child of children) {
      if (child.background) {
        childrenCopy.push({
          background: this.copyBackground(child.background),
        })
      }
      if (child.scenario && scenariosKeptIds.includes(child.scenario.id)) {
        childrenCopy.push({
          scenario: this.copyScenario(child.scenario),
        })
      }
    }

    return childrenCopy
  }

  private walkRuleChildren(children: readonly messages.RuleChild[]): messages.RuleChild[] {
    const childrenCopy: messages.RuleChild[] = []

    for (const child of children) {
      if (child.background) {
        childrenCopy.push({
          background: this.walkBackground(child.background),
        })
      }
      if (child.scenario) {
        childrenCopy.push({
          scenario: this.walkScenario(child.scenario),
        })
      }
    }
    return childrenCopy
  }

  protected walkBackground(background: messages.Background): messages.Background {
    const steps = this.walkAllSteps(background.steps)
    this.handlers.handleBackground(background)

    if (this.filters.acceptBackground(background) || steps.find((step) => step !== null)) {
      return this.copyBackground(background)
    }
  }

  private copyBackground(background: messages.Background): messages.Background {
    return {
      id: background.id,
      name: background.name,
      location: background.location,
      keyword: background.keyword,
      steps: background.steps.map((step) => this.copyStep(step)),
      description: background.description,
    }
  }

  protected walkScenario(scenario: messages.Scenario): messages.Scenario {
    const steps = this.walkAllSteps(scenario.steps)
    this.handlers.handleScenario(scenario)

    if (this.filters.acceptScenario(scenario) || steps.find((step) => step !== null)) {
      return this.copyScenario(scenario)
    }
  }

  private copyScenario(scenario: messages.Scenario): messages.Scenario {
    return {
      id: scenario.id,
      name: scenario.name,
      description: scenario.description,
      location: scenario.location,
      keyword: scenario.keyword,
      examples: scenario.examples,
      steps: scenario.steps.map((step) => this.copyStep(step)),
      tags: this.copyTags(scenario.tags),
    }
  }

  protected walkAllSteps(steps: readonly messages.Step[]): messages.Step[] {
    return steps.map((step) => this.walkStep(step))
  }

  protected walkStep(step: messages.Step): messages.Step {
    this.handlers.handleStep(step)
    if (!this.filters.acceptStep(step)) {
      return null
    }
    return this.copyStep(step)
  }

  private copyStep(step: messages.Step): messages.Step {
    return {
      id: step.id,
      keyword: step.keyword,
      keywordType: step.keywordType,
      location: step.location,
      text: step.text,
      dataTable: step.dataTable,
      docString: step.docString,
    }
  }
}
