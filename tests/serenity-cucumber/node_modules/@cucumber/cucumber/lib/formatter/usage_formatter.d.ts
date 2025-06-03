import Formatter, { IFormatterOptions } from './';
export default class UsageFormatter extends Formatter {
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    logUsage(): void;
}
