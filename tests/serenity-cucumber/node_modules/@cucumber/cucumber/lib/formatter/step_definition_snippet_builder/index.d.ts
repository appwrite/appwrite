import { KeywordType } from '../helpers';
import { ISnippetSnytax } from './snippet_syntax';
import { ParameterTypeRegistry } from '@cucumber/cucumber-expressions';
import * as messages from '@cucumber/messages';
export interface INewStepDefinitionSnippetBuilderOptions {
    snippetSyntax: ISnippetSnytax;
    parameterTypeRegistry: ParameterTypeRegistry;
}
export interface IBuildRequest {
    keywordType: KeywordType;
    pickleStep: messages.PickleStep;
}
export default class StepDefinitionSnippetBuilder {
    private readonly snippetSyntax;
    private readonly cucumberExpressionGenerator;
    constructor({ snippetSyntax, parameterTypeRegistry, }: INewStepDefinitionSnippetBuilderOptions);
    build({ keywordType, pickleStep }: IBuildRequest): string;
    getFunctionName(keywordType: KeywordType): string;
    getStepParameterNames(step: messages.PickleStep): string[];
}
