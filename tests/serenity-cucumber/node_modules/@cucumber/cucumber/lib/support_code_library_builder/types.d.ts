import * as messages from '@cucumber/messages';
import TestCaseHookDefinition from '../models/test_case_hook_definition';
import TestStepHookDefinition from '../models/test_step_hook_definition';
import TestRunHookDefinition from '../models/test_run_hook_definition';
import StepDefinition from '../models/step_definition';
import { IWorld } from './world';
import { SourcedParameterTypeRegistry } from './sourced_parameter_type_registry';
export type DefineStepPattern = string | RegExp;
export type ParallelAssignmentValidator = (pickle: messages.Pickle, runningPickles: messages.Pickle[]) => boolean;
export interface ITestCaseHookParameter {
    gherkinDocument: messages.GherkinDocument;
    pickle: messages.Pickle;
    result?: messages.TestStepResult;
    willBeRetried?: boolean;
    testCaseStartedId: string;
}
export interface ITestStepHookParameter {
    gherkinDocument: messages.GherkinDocument;
    pickle: messages.Pickle;
    pickleStep: messages.PickleStep;
    result: messages.TestStepResult;
    testCaseStartedId: string;
    testStepId: string;
}
export type TestCaseHookFunction<WorldType> = (this: WorldType, arg: ITestCaseHookParameter) => any | Promise<any>;
export type TestStepHookFunction<WorldType> = (this: WorldType, arg: ITestStepHookParameter) => any | Promise<any>;
export type TestStepFunction<WorldType> = (this: WorldType, ...args: any[]) => any | Promise<any>;
export interface IDefineStepOptions {
    timeout?: number;
    wrapperOptions?: any;
}
export interface IDefineTestCaseHookOptions {
    name?: string;
    tags?: string;
    timeout?: number;
}
export interface IDefineTestStepHookOptions {
    tags?: string;
    timeout?: number;
}
export interface IDefineTestRunHookOptions {
    timeout?: number;
}
export interface IParameterTypeDefinition<T> {
    name: string;
    regexp: readonly RegExp[] | readonly string[] | RegExp | string;
    transformer: (...match: string[]) => T;
    useForSnippets?: boolean;
    preferForRegexpMatch?: boolean;
}
export type IDefineStep = (<WorldType = IWorld>(pattern: DefineStepPattern, code: TestStepFunction<WorldType>) => void) & (<WorldType = IWorld>(pattern: DefineStepPattern, options: IDefineStepOptions, code: TestStepFunction<WorldType>) => void);
export interface IDefineSupportCodeMethods {
    defineParameterType: (options: IParameterTypeDefinition<any>) => void;
    defineStep: IDefineStep;
    setDefaultTimeout: (milliseconds: number) => void;
    setDefinitionFunctionWrapper: (fn: Function) => void;
    setParallelCanAssign: (fn: ParallelAssignmentValidator) => void;
    setWorldConstructor: (fn: any) => void;
    After: (<WorldType = IWorld>(code: TestCaseHookFunction<WorldType>) => void) & (<WorldType = IWorld>(tags: string, code: TestCaseHookFunction<WorldType>) => void) & (<WorldType = IWorld>(options: IDefineTestCaseHookOptions, code: TestCaseHookFunction<WorldType>) => void);
    AfterStep: (<WorldType = IWorld>(code: TestStepHookFunction<WorldType>) => void) & (<WorldType = IWorld>(tags: string, code: TestStepHookFunction<WorldType>) => void) & (<WorldType = IWorld>(options: IDefineTestStepHookOptions, code: TestStepHookFunction<WorldType>) => void);
    AfterAll: ((code: Function) => void) & ((options: IDefineTestRunHookOptions, code: Function) => void);
    Before: (<WorldType = IWorld>(code: TestCaseHookFunction<WorldType>) => void) & (<WorldType = IWorld>(tags: string, code: TestCaseHookFunction<WorldType>) => void) & (<WorldType = IWorld>(options: IDefineTestCaseHookOptions, code: TestCaseHookFunction<WorldType>) => void);
    BeforeStep: (<WorldType = IWorld>(code: TestStepHookFunction<WorldType>) => void) & (<WorldType = IWorld>(tags: string, code: TestStepHookFunction<WorldType>) => void) & (<WorldType = IWorld>(options: IDefineTestStepHookOptions, code: TestStepHookFunction<WorldType>) => void);
    BeforeAll: ((code: Function) => void) & ((options: IDefineTestRunHookOptions, code: Function) => void);
    Given: IDefineStep;
    Then: IDefineStep;
    When: IDefineStep;
}
export interface ISupportCodeCoordinates {
    requireModules: string[];
    requirePaths: string[];
    importPaths: string[];
}
export interface ISupportCodeLibrary {
    readonly originalCoordinates: ISupportCodeCoordinates;
    readonly afterTestCaseHookDefinitions: TestCaseHookDefinition[];
    readonly afterTestStepHookDefinitions: TestStepHookDefinition[];
    readonly afterTestRunHookDefinitions: TestRunHookDefinition[];
    readonly beforeTestCaseHookDefinitions: TestCaseHookDefinition[];
    readonly beforeTestStepHookDefinitions: TestStepHookDefinition[];
    readonly beforeTestRunHookDefinitions: TestRunHookDefinition[];
    readonly defaultTimeout: number;
    readonly stepDefinitions: StepDefinition[];
    readonly undefinedParameterTypes: messages.UndefinedParameterType[];
    readonly parameterTypeRegistry: SourcedParameterTypeRegistry;
    readonly World: any;
    readonly parallelCanAssign: ParallelAssignmentValidator;
}
