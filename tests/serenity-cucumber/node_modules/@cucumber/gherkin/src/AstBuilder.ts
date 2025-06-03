import AstNode from './AstNode'
import * as messages from '@cucumber/messages'
import { RuleType, TokenType } from './Parser'
import { AstBuilderException } from './Errors'
import IToken from './IToken'
import { IAstBuilder } from './IAstBuilder'

export default class AstBuilder implements IAstBuilder<AstNode, TokenType, RuleType> {
  stack: AstNode[]
  comments: messages.Comment[]
  readonly newId: messages.IdGenerator.NewId

  constructor(newId: messages.IdGenerator.NewId) {
    this.newId = newId
    if (!newId) {
      throw new Error('No newId')
    }
    this.reset()
  }

  reset() {
    this.stack = [new AstNode(RuleType.None)]
    this.comments = []
  }

  startRule(ruleType: RuleType) {
    this.stack.push(new AstNode(ruleType))
  }

  endRule() {
    const node = this.stack.pop()
    const transformedNode = this.transformNode(node)
    this.currentNode().add(node.ruleType, transformedNode)
  }

  build(token: IToken<TokenType>) {
    if (token.matchedType === TokenType.Comment) {
      this.comments.push({
        location: this.getLocation(token),
        text: token.matchedText,
      })
    } else {
      this.currentNode().add(token.matchedType, token)
    }
  }

  getResult() {
    return this.currentNode().getSingle(RuleType.GherkinDocument)
  }

  currentNode() {
    return this.stack[this.stack.length - 1]
  }

  getLocation(token: IToken<TokenType>, column?: number): messages.Location {
    return !column ? token.location : { line: token.location.line, column }
  }

  getTags(node: AstNode) {
    const tags: messages.Tag[] = []
    const tagsNode = node.getSingle(RuleType.Tags)
    if (!tagsNode) {
      return tags
    }
    const tokens = tagsNode.getTokens(TokenType.TagLine)
    for (const token of tokens) {
      for (const tagItem of token.matchedItems) {
        tags.push({
          location: this.getLocation(token, tagItem.column),
          name: tagItem.text,
          id: this.newId(),
        })
      }
    }
    return tags
  }

  getCells(tableRowToken: IToken<TokenType>) {
    return tableRowToken.matchedItems.map((cellItem) => ({
      location: this.getLocation(tableRowToken, cellItem.column),
      value: cellItem.text,
    }))
  }

  getDescription(node: AstNode) {
    return node.getSingle(RuleType.Description) || ''
  }

  getSteps(node: AstNode) {
    return node.getItems(RuleType.Step)
  }

  getTableRows(node: AstNode) {
    const rows = node.getTokens(TokenType.TableRow).map((token) => ({
      id: this.newId(),
      location: this.getLocation(token),
      cells: this.getCells(token),
    }))
    this.ensureCellCount(rows)
    return rows.length === 0 ? [] : rows
  }

  ensureCellCount(rows: messages.TableRow[]) {
    if (rows.length === 0) {
      return
    }
    const cellCount = rows[0].cells.length

    rows.forEach((row) => {
      if (row.cells.length !== cellCount) {
        throw AstBuilderException.create('inconsistent cell count within the table', row.location)
      }
    })
  }

  transformNode(node: AstNode) {
    switch (node.ruleType) {
      case RuleType.Step: {
        const stepLine = node.getToken(TokenType.StepLine)
        const dataTable = node.getSingle(RuleType.DataTable)
        const docString = node.getSingle(RuleType.DocString)

        const location = this.getLocation(stepLine)
        const step: messages.Step = {
          id: this.newId(),
          location,
          keyword: stepLine.matchedKeyword,
          keywordType: stepLine.matchedKeywordType,
          text: stepLine.matchedText,
          dataTable: dataTable,
          docString: docString,
        }
        return step
      }
      case RuleType.DocString: {
        const separatorToken = node.getTokens(TokenType.DocStringSeparator)[0]
        const mediaType =
          separatorToken.matchedText.length > 0 ? separatorToken.matchedText : undefined
        const lineTokens = node.getTokens(TokenType.Other)
        const content = lineTokens.map((t) => t.matchedText).join('\n')

        const result: messages.DocString = {
          location: this.getLocation(separatorToken),
          content,
          delimiter: separatorToken.matchedKeyword,
        }
        // conditionally add this like this (needed to make tests pass on node 0.10 as well as 4.0)
        if (mediaType) {
          result.mediaType = mediaType
        }
        return result
      }
      case RuleType.DataTable: {
        const rows = this.getTableRows(node)
        const dataTable: messages.DataTable = {
          location: rows[0].location,
          rows,
        }
        return dataTable
      }
      case RuleType.Background: {
        const backgroundLine = node.getToken(TokenType.BackgroundLine)
        const description = this.getDescription(node)
        const steps = this.getSteps(node)

        const background: messages.Background = {
          id: this.newId(),
          location: this.getLocation(backgroundLine),
          keyword: backgroundLine.matchedKeyword,
          name: backgroundLine.matchedText,
          description,
          steps,
        }
        return background
      }
      case RuleType.ScenarioDefinition: {
        const tags = this.getTags(node)
        const scenarioNode = node.getSingle(RuleType.Scenario)
        const scenarioLine = scenarioNode.getToken(TokenType.ScenarioLine)
        const description = this.getDescription(scenarioNode)
        const steps = this.getSteps(scenarioNode)
        const examples = scenarioNode.getItems(RuleType.ExamplesDefinition)
        const scenario: messages.Scenario = {
          id: this.newId(),
          tags,
          location: this.getLocation(scenarioLine),
          keyword: scenarioLine.matchedKeyword,
          name: scenarioLine.matchedText,
          description,
          steps,
          examples,
        }
        return scenario
      }
      case RuleType.ExamplesDefinition: {
        const tags = this.getTags(node)
        const examplesNode = node.getSingle(RuleType.Examples)
        const examplesLine = examplesNode.getToken(TokenType.ExamplesLine)
        const description = this.getDescription(examplesNode)
        const examplesTable: messages.TableRow[] = examplesNode.getSingle(RuleType.ExamplesTable)

        const examples: messages.Examples = {
          id: this.newId(),
          tags,
          location: this.getLocation(examplesLine),
          keyword: examplesLine.matchedKeyword,
          name: examplesLine.matchedText,
          description,
          tableHeader: examplesTable ? examplesTable[0] : undefined,
          tableBody: examplesTable ? examplesTable.slice(1) : [],
        }
        return examples
      }
      case RuleType.ExamplesTable: {
        return this.getTableRows(node)
      }
      case RuleType.Description: {
        let lineTokens = node.getTokens(TokenType.Other)
        // Trim trailing empty lines
        let end = lineTokens.length
        while (end > 0 && lineTokens[end - 1].line.trimmedLineText === '') {
          end--
        }
        lineTokens = lineTokens.slice(0, end)

        return lineTokens.map((token) => token.matchedText).join('\n')
      }

      case RuleType.Feature: {
        const header = node.getSingle(RuleType.FeatureHeader)
        if (!header) {
          return null
        }
        const tags = this.getTags(header)
        const featureLine = header.getToken(TokenType.FeatureLine)
        if (!featureLine) {
          return null
        }
        const children: messages.FeatureChild[] = []
        const background = node.getSingle(RuleType.Background)
        if (background) {
          children.push({
            background,
          })
        }
        for (const scenario of node.getItems(RuleType.ScenarioDefinition)) {
          children.push({
            scenario,
          })
        }
        for (const rule of node.getItems(RuleType.Rule)) {
          children.push({
            rule,
          })
        }

        const description = this.getDescription(header)
        const language = featureLine.matchedGherkinDialect

        const feature: messages.Feature = {
          tags,
          location: this.getLocation(featureLine),
          language,
          keyword: featureLine.matchedKeyword,
          name: featureLine.matchedText,
          description,
          children,
        }
        return feature
      }

      case RuleType.Rule: {
        const header = node.getSingle(RuleType.RuleHeader)
        if (!header) {
          return null
        }
        const ruleLine = header.getToken(TokenType.RuleLine)
        if (!ruleLine) {
          return null
        }
        const tags = this.getTags(header)
        const children: messages.RuleChild[] = []
        const background = node.getSingle(RuleType.Background)
        if (background) {
          children.push({
            background,
          })
        }
        for (const scenario of node.getItems(RuleType.ScenarioDefinition)) {
          children.push({
            scenario,
          })
        }
        const description = this.getDescription(header)

        const rule: messages.Rule = {
          id: this.newId(),
          location: this.getLocation(ruleLine),
          keyword: ruleLine.matchedKeyword,
          name: ruleLine.matchedText,
          description,
          children,
          tags,
        }
        return rule
      }
      case RuleType.GherkinDocument: {
        const feature = node.getSingle(RuleType.Feature)

        const gherkinDocument: messages.GherkinDocument = {
          feature,
          comments: this.comments,
        }
        return gherkinDocument
      }
      default:
        return node
    }
  }
}
