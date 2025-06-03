import SummaryFormatter from './summary_formatter';
import { IFormatterOptions } from './index';
import * as messages from '@cucumber/messages';
import ITestStepFinished = messages.TestStepFinished;
export default class ProgressFormatter extends SummaryFormatter {
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    logProgress({ testStepResult: { status } }: ITestStepFinished): void;
}
