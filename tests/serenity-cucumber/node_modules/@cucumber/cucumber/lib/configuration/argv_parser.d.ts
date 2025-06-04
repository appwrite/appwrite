import { IConfiguration } from './types';
export interface IParsedArgvOptions {
    config?: string;
    i18nKeywords?: string;
    i18nLanguages?: boolean;
    profile: string[];
}
export interface IParsedArgv {
    options: IParsedArgvOptions;
    configuration: Partial<IConfiguration>;
}
declare const ArgvParser: {
    collect<T>(val: T, memo?: T[]): T[];
    mergeJson(option: string): (str: string, memo?: object) => object;
    mergeTags(value: string, memo?: string): string;
    validateCountOption(value: string, optionName: string): number;
    validateLanguage(value: string): string;
    parse(argv: string[]): IParsedArgv;
};
export default ArgvParser;
