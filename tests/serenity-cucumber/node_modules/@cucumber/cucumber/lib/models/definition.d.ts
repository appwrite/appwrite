import * as messages from '@cucumber/messages';
import { ITestCaseHookParameter } from '../support_code_library_builder/types';
import { Expression } from '@cucumber/cucumber-expressions';
import { GherkinStepKeyword } from './gherkin_step_keyword';
export interface IGetInvocationDataRequest {
    hookParameter: ITestCaseHookParameter;
    step: messages.PickleStep;
    world: any;
}
export interface IGetInvocationDataResponse {
    getInvalidCodeLengthMessage: () => string;
    parameters: any[];
    validCodeLengths: number[];
}
export interface IDefinitionOptions {
    timeout?: number;
    wrapperOptions?: any;
}
export interface IHookDefinitionOptions extends IDefinitionOptions {
    name?: string;
    tags?: string;
}
export interface IDefinitionParameters<T extends IDefinitionOptions> {
    code: Function;
    id: string;
    line: number;
    options: T;
    unwrappedCode?: Function;
    uri: string;
}
export interface IStepDefinitionParameters extends IDefinitionParameters<IDefinitionOptions> {
    keyword: GherkinStepKeyword;
    pattern: string | RegExp;
    expression: Expression;
}
export interface IDefinition {
    readonly code: Function;
    readonly id: string;
    readonly line: number;
    readonly options: IDefinitionOptions;
    readonly unwrappedCode: Function;
    readonly uri: string;
    getInvocationParameters: (options: IGetInvocationDataRequest) => Promise<IGetInvocationDataResponse>;
}
export default abstract class Definition {
    readonly code: Function;
    readonly id: string;
    readonly line: number;
    readonly options: IDefinitionOptions;
    readonly unwrappedCode: Function;
    readonly uri: string;
    constructor({ code, id, line, options, unwrappedCode, uri, }: IDefinitionParameters<IDefinitionOptions>);
    buildInvalidCodeLengthMessage(syncOrPromiseLength: number | string, callbackLength: number | string): string;
    baseGetInvalidCodeLengthMessage(parameters: any[]): string;
}
