import { IColorFns } from '../get_color_fns';
import { ITestCaseAttempt } from './event_data_collector';
import * as messages from '@cucumber/messages';
export interface IFormatSummaryRequest {
    colorFns: IColorFns;
    testCaseAttempts: ITestCaseAttempt[];
    testRunDuration: messages.Duration;
}
export declare function formatSummary({ colorFns, testCaseAttempts, testRunDuration, }: IFormatSummaryRequest): string;
