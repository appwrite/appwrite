import Formatter from '../.';
declare const Formatters: {
    getFormatters(): Record<string, typeof Formatter>;
    buildFormattersDocumentationString(): string;
};
export default Formatters;
