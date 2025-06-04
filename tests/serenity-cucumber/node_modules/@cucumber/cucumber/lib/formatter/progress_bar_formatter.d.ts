import Formatter, { IFormatterOptions } from './';
import ProgressBar from 'progress';
import * as messages from '@cucumber/messages';
export default class ProgressBarFormatter extends Formatter {
    private numberOfSteps;
    private testRunStarted;
    private issueCount;
    progressBar: ProgressBar;
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    incrementStepCount(pickleId: string): void;
    initializeProgressBar(): void;
    logProgress({ testStepId, testCaseStartedId, }: messages.TestStepFinished): void;
    logUndefinedParametertype(parameterType: messages.UndefinedParameterType): void;
    logErrorIfNeeded(testCaseFinished: messages.TestCaseFinished): void;
    logSummary(testRunFinished: messages.TestRunFinished): void;
    parseEnvelope(envelope: messages.Envelope): void;
}
