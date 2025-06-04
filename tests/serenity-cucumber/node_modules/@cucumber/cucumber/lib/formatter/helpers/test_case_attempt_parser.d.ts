import * as messages from '@cucumber/messages';
import { ITestCaseAttempt } from './event_data_collector';
import StepDefinitionSnippetBuilder from '../step_definition_snippet_builder';
import { ISupportCodeLibrary } from '../../support_code_library_builder/types';
import { ILineAndUri } from '../../types';
export interface IParsedTestStep {
    actionLocation?: ILineAndUri;
    argument?: messages.PickleStepArgument;
    attachments: messages.Attachment[];
    keyword: string;
    name?: string;
    result: messages.TestStepResult;
    snippet?: string;
    sourceLocation?: ILineAndUri;
    text?: string;
}
export interface IParsedTestCase {
    attempt: number;
    name: string;
    sourceLocation?: ILineAndUri;
    worstTestStepResult: messages.TestStepResult;
}
export interface IParsedTestCaseAttempt {
    testCase: IParsedTestCase;
    testSteps: IParsedTestStep[];
}
export interface IParseTestCaseAttemptRequest {
    testCaseAttempt: ITestCaseAttempt;
    snippetBuilder: StepDefinitionSnippetBuilder;
    supportCodeLibrary: ISupportCodeLibrary;
}
export declare function parseTestCaseAttempt({ testCaseAttempt, snippetBuilder, supportCodeLibrary, }: IParseTestCaseAttemptRequest): IParsedTestCaseAttempt;
