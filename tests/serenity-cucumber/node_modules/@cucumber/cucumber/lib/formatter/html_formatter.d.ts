import Formatter, { IFormatterOptions } from '.';
export default class HtmlFormatter extends Formatter {
    private readonly _htmlStream;
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    finished(): Promise<void>;
}
