import * as messages from '@cucumber/messages';
import { IColorFns } from '../get_color_fns';
import StepDefinitionSnippetBuilder from '../step_definition_snippet_builder';
import { ISupportCodeLibrary } from '../../support_code_library_builder/types';
import { ITestCaseAttempt } from './event_data_collector';
export declare function isFailure(result: messages.TestStepResult, willBeRetried?: boolean): boolean;
export declare function isWarning(result: messages.TestStepResult, willBeRetried?: boolean): boolean;
export declare function isIssue(result: messages.TestStepResult): boolean;
export interface IFormatIssueRequest {
    colorFns: IColorFns;
    number: number;
    snippetBuilder: StepDefinitionSnippetBuilder;
    testCaseAttempt: ITestCaseAttempt;
    supportCodeLibrary: ISupportCodeLibrary;
    printAttachments?: boolean;
}
export declare function formatIssue({ colorFns, number, snippetBuilder, testCaseAttempt, supportCodeLibrary, printAttachments, }: IFormatIssueRequest): string;
export declare function formatUndefinedParameterTypes(undefinedParameterTypes: messages.UndefinedParameterType[]): string;
export declare function formatUndefinedParameterType(parameterType: messages.UndefinedParameterType): string;
