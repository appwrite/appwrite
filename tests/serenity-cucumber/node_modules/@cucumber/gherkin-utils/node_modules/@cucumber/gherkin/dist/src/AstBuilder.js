"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const AstNode_1 = __importDefault(require("./AstNode"));
const Parser_1 = require("./Parser");
const Errors_1 = require("./Errors");
class AstBuilder {
    constructor(newId) {
        this.newId = newId;
        if (!newId) {
            throw new Error('No newId');
        }
        this.reset();
    }
    reset() {
        this.stack = [new AstNode_1.default(Parser_1.RuleType.None)];
        this.comments = [];
    }
    startRule(ruleType) {
        this.stack.push(new AstNode_1.default(ruleType));
    }
    endRule() {
        const node = this.stack.pop();
        const transformedNode = this.transformNode(node);
        this.currentNode().add(node.ruleType, transformedNode);
    }
    build(token) {
        if (token.matchedType === Parser_1.TokenType.Comment) {
            this.comments.push({
                location: this.getLocation(token),
                text: token.matchedText,
            });
        }
        else {
            this.currentNode().add(token.matchedType, token);
        }
    }
    getResult() {
        return this.currentNode().getSingle(Parser_1.RuleType.GherkinDocument);
    }
    currentNode() {
        return this.stack[this.stack.length - 1];
    }
    getLocation(token, column) {
        return !column ? token.location : { line: token.location.line, column };
    }
    getTags(node) {
        const tags = [];
        const tagsNode = node.getSingle(Parser_1.RuleType.Tags);
        if (!tagsNode) {
            return tags;
        }
        const tokens = tagsNode.getTokens(Parser_1.TokenType.TagLine);
        for (const token of tokens) {
            for (const tagItem of token.matchedItems) {
                tags.push({
                    location: this.getLocation(token, tagItem.column),
                    name: tagItem.text,
                    id: this.newId(),
                });
            }
        }
        return tags;
    }
    getCells(tableRowToken) {
        return tableRowToken.matchedItems.map((cellItem) => ({
            location: this.getLocation(tableRowToken, cellItem.column),
            value: cellItem.text,
        }));
    }
    getDescription(node) {
        return node.getSingle(Parser_1.RuleType.Description) || '';
    }
    getSteps(node) {
        return node.getItems(Parser_1.RuleType.Step);
    }
    getTableRows(node) {
        const rows = node.getTokens(Parser_1.TokenType.TableRow).map((token) => ({
            id: this.newId(),
            location: this.getLocation(token),
            cells: this.getCells(token),
        }));
        this.ensureCellCount(rows);
        return rows.length === 0 ? [] : rows;
    }
    ensureCellCount(rows) {
        if (rows.length === 0) {
            return;
        }
        const cellCount = rows[0].cells.length;
        rows.forEach((row) => {
            if (row.cells.length !== cellCount) {
                throw Errors_1.AstBuilderException.create('inconsistent cell count within the table', row.location);
            }
        });
    }
    transformNode(node) {
        switch (node.ruleType) {
            case Parser_1.RuleType.Step: {
                const stepLine = node.getToken(Parser_1.TokenType.StepLine);
                const dataTable = node.getSingle(Parser_1.RuleType.DataTable);
                const docString = node.getSingle(Parser_1.RuleType.DocString);
                const location = this.getLocation(stepLine);
                const step = {
                    id: this.newId(),
                    location,
                    keyword: stepLine.matchedKeyword,
                    keywordType: stepLine.matchedKeywordType,
                    text: stepLine.matchedText,
                    dataTable: dataTable,
                    docString: docString,
                };
                return step;
            }
            case Parser_1.RuleType.DocString: {
                const separatorToken = node.getTokens(Parser_1.TokenType.DocStringSeparator)[0];
                const mediaType = separatorToken.matchedText.length > 0 ? separatorToken.matchedText : undefined;
                const lineTokens = node.getTokens(Parser_1.TokenType.Other);
                const content = lineTokens.map((t) => t.matchedText).join('\n');
                const result = {
                    location: this.getLocation(separatorToken),
                    content,
                    delimiter: separatorToken.matchedKeyword,
                };
                // conditionally add this like this (needed to make tests pass on node 0.10 as well as 4.0)
                if (mediaType) {
                    result.mediaType = mediaType;
                }
                return result;
            }
            case Parser_1.RuleType.DataTable: {
                const rows = this.getTableRows(node);
                const dataTable = {
                    location: rows[0].location,
                    rows,
                };
                return dataTable;
            }
            case Parser_1.RuleType.Background: {
                const backgroundLine = node.getToken(Parser_1.TokenType.BackgroundLine);
                const description = this.getDescription(node);
                const steps = this.getSteps(node);
                const background = {
                    id: this.newId(),
                    location: this.getLocation(backgroundLine),
                    keyword: backgroundLine.matchedKeyword,
                    name: backgroundLine.matchedText,
                    description,
                    steps,
                };
                return background;
            }
            case Parser_1.RuleType.ScenarioDefinition: {
                const tags = this.getTags(node);
                const scenarioNode = node.getSingle(Parser_1.RuleType.Scenario);
                const scenarioLine = scenarioNode.getToken(Parser_1.TokenType.ScenarioLine);
                const description = this.getDescription(scenarioNode);
                const steps = this.getSteps(scenarioNode);
                const examples = scenarioNode.getItems(Parser_1.RuleType.ExamplesDefinition);
                const scenario = {
                    id: this.newId(),
                    tags,
                    location: this.getLocation(scenarioLine),
                    keyword: scenarioLine.matchedKeyword,
                    name: scenarioLine.matchedText,
                    description,
                    steps,
                    examples,
                };
                return scenario;
            }
            case Parser_1.RuleType.ExamplesDefinition: {
                const tags = this.getTags(node);
                const examplesNode = node.getSingle(Parser_1.RuleType.Examples);
                const examplesLine = examplesNode.getToken(Parser_1.TokenType.ExamplesLine);
                const description = this.getDescription(examplesNode);
                const examplesTable = examplesNode.getSingle(Parser_1.RuleType.ExamplesTable);
                const examples = {
                    id: this.newId(),
                    tags,
                    location: this.getLocation(examplesLine),
                    keyword: examplesLine.matchedKeyword,
                    name: examplesLine.matchedText,
                    description,
                    tableHeader: examplesTable ? examplesTable[0] : undefined,
                    tableBody: examplesTable ? examplesTable.slice(1) : [],
                };
                return examples;
            }
            case Parser_1.RuleType.ExamplesTable: {
                return this.getTableRows(node);
            }
            case Parser_1.RuleType.Description: {
                let lineTokens = node.getTokens(Parser_1.TokenType.Other);
                // Trim trailing empty lines
                let end = lineTokens.length;
                while (end > 0 && lineTokens[end - 1].line.trimmedLineText === '') {
                    end--;
                }
                lineTokens = lineTokens.slice(0, end);
                return lineTokens.map((token) => token.matchedText).join('\n');
            }
            case Parser_1.RuleType.Feature: {
                const header = node.getSingle(Parser_1.RuleType.FeatureHeader);
                if (!header) {
                    return null;
                }
                const tags = this.getTags(header);
                const featureLine = header.getToken(Parser_1.TokenType.FeatureLine);
                if (!featureLine) {
                    return null;
                }
                const children = [];
                const background = node.getSingle(Parser_1.RuleType.Background);
                if (background) {
                    children.push({
                        background,
                    });
                }
                for (const scenario of node.getItems(Parser_1.RuleType.ScenarioDefinition)) {
                    children.push({
                        scenario,
                    });
                }
                for (const rule of node.getItems(Parser_1.RuleType.Rule)) {
                    children.push({
                        rule,
                    });
                }
                const description = this.getDescription(header);
                const language = featureLine.matchedGherkinDialect;
                const feature = {
                    tags,
                    location: this.getLocation(featureLine),
                    language,
                    keyword: featureLine.matchedKeyword,
                    name: featureLine.matchedText,
                    description,
                    children,
                };
                return feature;
            }
            case Parser_1.RuleType.Rule: {
                const header = node.getSingle(Parser_1.RuleType.RuleHeader);
                if (!header) {
                    return null;
                }
                const ruleLine = header.getToken(Parser_1.TokenType.RuleLine);
                if (!ruleLine) {
                    return null;
                }
                const tags = this.getTags(header);
                const children = [];
                const background = node.getSingle(Parser_1.RuleType.Background);
                if (background) {
                    children.push({
                        background,
                    });
                }
                for (const scenario of node.getItems(Parser_1.RuleType.ScenarioDefinition)) {
                    children.push({
                        scenario,
                    });
                }
                const description = this.getDescription(header);
                const rule = {
                    id: this.newId(),
                    location: this.getLocation(ruleLine),
                    keyword: ruleLine.matchedKeyword,
                    name: ruleLine.matchedText,
                    description,
                    children,
                    tags,
                };
                return rule;
            }
            case Parser_1.RuleType.GherkinDocument: {
                const feature = node.getSingle(Parser_1.RuleType.Feature);
                const gherkinDocument = {
                    feature,
                    comments: this.comments,
                };
                return gherkinDocument;
            }
            default:
                return node;
        }
    }
}
exports.default = AstBuilder;
//# sourceMappingURL=AstBuilder.js.map