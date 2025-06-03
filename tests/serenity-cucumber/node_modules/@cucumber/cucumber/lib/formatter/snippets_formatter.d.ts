import Formatter, { IFormatterOptions } from './';
export default class SnippetsFormatter extends Formatter {
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    logSnippets(): void;
}
