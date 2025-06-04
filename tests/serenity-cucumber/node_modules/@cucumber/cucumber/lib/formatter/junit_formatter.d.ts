import Formatter, { IFormatterOptions } from './';
export default class JunitFormatter extends Formatter {
    private readonly names;
    private readonly suiteName;
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    private getTestCases;
    private getTestSteps;
    private getTestStep;
    private getTestCaseResult;
    private durationToSeconds;
    private nameOrDefault;
    private getTestCaseName;
    private formatTestSteps;
    private onTestRunFinished;
    private buildXmlReport;
}
