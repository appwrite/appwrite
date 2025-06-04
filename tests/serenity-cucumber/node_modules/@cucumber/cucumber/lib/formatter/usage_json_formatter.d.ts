import Formatter, { IFormatterOptions } from './';
export default class UsageJsonFormatter extends Formatter {
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    logUsage(): void;
    replacer(key: string, value: any): any;
}
