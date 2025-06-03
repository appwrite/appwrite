/// <reference types="node" />
import * as messages from '@cucumber/messages';
import { IdGenerator } from '@cucumber/messages';
import { EventEmitter } from 'events';
import { ISupportCodeLibrary, ITestCaseHookParameter } from '../support_code_library_builder/types';
import TestCaseHookDefinition from '../models/test_case_hook_definition';
import TestStepHookDefinition from '../models/test_step_hook_definition';
import { IDefinition } from '../models/definition';
import { IStopwatch } from './stopwatch';
export interface INewTestCaseRunnerOptions {
    eventBroadcaster: EventEmitter;
    stopwatch: IStopwatch;
    gherkinDocument: messages.GherkinDocument;
    newId: IdGenerator.NewId;
    pickle: messages.Pickle;
    testCase: messages.TestCase;
    retries: number;
    skip: boolean;
    filterStackTraces: boolean;
    supportCodeLibrary: ISupportCodeLibrary;
    worldParameters: any;
}
export default class TestCaseRunner {
    private readonly attachmentManager;
    private currentTestCaseStartedId;
    private currentTestStepId;
    private readonly eventBroadcaster;
    private readonly stopwatch;
    private readonly gherkinDocument;
    private readonly newId;
    private readonly pickle;
    private readonly testCase;
    private readonly maxAttempts;
    private readonly skip;
    private readonly filterStackTraces;
    private readonly supportCodeLibrary;
    private testStepResults;
    private world;
    private readonly worldParameters;
    constructor({ eventBroadcaster, stopwatch, gherkinDocument, newId, pickle, testCase, retries, skip, filterStackTraces, supportCodeLibrary, worldParameters, }: INewTestCaseRunnerOptions);
    resetTestProgressData(): void;
    getBeforeStepHookDefinitions(): TestStepHookDefinition[];
    getAfterStepHookDefinitions(): TestStepHookDefinition[];
    getWorstStepResult(): messages.TestStepResult;
    invokeStep(step: messages.PickleStep, stepDefinition: IDefinition, hookParameter?: any): Promise<messages.TestStepResult>;
    isSkippingSteps(): boolean;
    shouldSkipHook(isBeforeHook: boolean): boolean;
    aroundTestStep(testStepId: string, runStepFn: () => Promise<messages.TestStepResult>): Promise<void>;
    run(): Promise<messages.TestStepResultStatus>;
    runAttempt(attempt: number, moreAttemptsRemaining: boolean): Promise<boolean>;
    runHook(hookDefinition: TestCaseHookDefinition, hookParameter: ITestCaseHookParameter, isBeforeHook: boolean): Promise<messages.TestStepResult>;
    runStepHooks(stepHooks: TestStepHookDefinition[], pickleStep: messages.PickleStep, stepResult?: messages.TestStepResult): Promise<messages.TestStepResult[]>;
    runStep(pickleStep: messages.PickleStep, testStep: messages.TestStep): Promise<messages.TestStepResult>;
}
