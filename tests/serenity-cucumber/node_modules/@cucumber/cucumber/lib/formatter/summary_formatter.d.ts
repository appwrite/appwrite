import Formatter, { IFormatterOptions } from './';
import * as messages from '@cucumber/messages';
import { ITestCaseAttempt } from './helpers/event_data_collector';
interface ILogIssuesRequest {
    issues: ITestCaseAttempt[];
    title: string;
}
export default class SummaryFormatter extends Formatter {
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    logSummary(testRunDuration: messages.Duration): void;
    logIssues({ issues, title }: ILogIssuesRequest): void;
}
export {};
