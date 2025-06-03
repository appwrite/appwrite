import Formatter, { IFormatterOptions } from './';
interface UriToLinesMap {
    [uri: string]: number[];
}
export default class RerunFormatter extends Formatter {
    protected readonly separator: string;
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    getFailureMap(): UriToLinesMap;
    formatFailedTestCases(): string;
    logFailedTestCases(): void;
}
export {};
