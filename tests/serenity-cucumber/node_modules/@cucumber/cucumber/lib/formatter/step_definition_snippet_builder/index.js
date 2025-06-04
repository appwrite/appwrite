"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const helpers_1 = require("../helpers");
const step_arguments_1 = require("../../step_arguments");
const cucumber_expressions_1 = require("@cucumber/cucumber-expressions");
const value_checker_1 = require("../../value_checker");
class StepDefinitionSnippetBuilder {
    constructor({ snippetSyntax, parameterTypeRegistry, }) {
        this.snippetSyntax = snippetSyntax;
        this.cucumberExpressionGenerator = new cucumber_expressions_1.CucumberExpressionGenerator(() => parameterTypeRegistry.parameterTypes);
    }
    build({ keywordType, pickleStep }) {
        const comment = 'Write code here that turns the phrase above into concrete actions';
        const functionName = this.getFunctionName(keywordType);
        const generatedExpressions = this.cucumberExpressionGenerator.generateExpressions(pickleStep.text);
        const stepParameterNames = this.getStepParameterNames(pickleStep);
        return this.snippetSyntax.build({
            comment,
            functionName,
            generatedExpressions,
            stepParameterNames,
        });
    }
    getFunctionName(keywordType) {
        switch (keywordType) {
            case helpers_1.KeywordType.Event:
                return 'When';
            case helpers_1.KeywordType.Outcome:
                return 'Then';
            case helpers_1.KeywordType.Precondition:
                return 'Given';
        }
    }
    getStepParameterNames(step) {
        if ((0, value_checker_1.doesHaveValue)(step.argument)) {
            const argumentName = (0, step_arguments_1.parseStepArgument)(step.argument, {
                dataTable: () => 'dataTable',
                docString: () => 'docString',
            });
            return [argumentName];
        }
        return [];
    }
}
exports.default = StepDefinitionSnippetBuilder;
//# sourceMappingURL=index.js.map