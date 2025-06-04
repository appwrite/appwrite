import { IColorFns } from '../get_color_fns';
import { ITestCaseAttempt } from './event_data_collector';
import StepDefinitionSnippetBuilder from '../step_definition_snippet_builder';
import { ISupportCodeLibrary } from '../../support_code_library_builder/types';
export interface IFormatTestCaseAttemptRequest {
    colorFns: IColorFns;
    testCaseAttempt: ITestCaseAttempt;
    snippetBuilder: StepDefinitionSnippetBuilder;
    supportCodeLibrary: ISupportCodeLibrary;
    printAttachments?: boolean;
}
export declare function formatTestCaseAttempt({ colorFns, snippetBuilder, supportCodeLibrary, testCaseAttempt, printAttachments, }: IFormatTestCaseAttemptRequest): string;
