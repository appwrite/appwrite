import * as messages from '@cucumber/messages';
import { ITestCaseHookParameter } from '../support_code_library_builder/types';
import { IDefinition } from '../models/definition';
export interface IRunOptions {
    defaultTimeout: number;
    filterStackTraces: boolean;
    hookParameter: ITestCaseHookParameter;
    step: messages.PickleStep;
    stepDefinition: IDefinition;
    world: any;
}
export declare function run({ defaultTimeout, filterStackTraces, hookParameter, step, stepDefinition, world, }: IRunOptions): Promise<messages.TestStepResult>;
declare const _default: {
    run: typeof run;
};
export default _default;
