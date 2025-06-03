import * as messages from '@cucumber/messages';
import { IRuntimeOptions } from '../index';
export interface IWorkerCommand {
    initialize?: IWorkerCommandInitialize;
    run?: IWorkerCommandRun;
    finalize?: boolean;
}
export interface IWorkerCommandInitialize {
    filterStacktraces: boolean;
    requireModules: string[];
    requirePaths: string[];
    importPaths: string[];
    supportCodeIds?: ICanonicalSupportCodeIds;
    options: IRuntimeOptions;
}
export interface ICanonicalSupportCodeIds {
    stepDefinitionIds: string[];
    beforeTestCaseHookDefinitionIds: string[];
    afterTestCaseHookDefinitionIds: string[];
}
export interface IWorkerCommandRun {
    retries: number;
    skip: boolean;
    elapsed: messages.Duration;
    pickle: messages.Pickle;
    testCase: messages.TestCase;
    gherkinDocument: messages.GherkinDocument;
}
export interface ICoordinatorReport {
    jsonEnvelope?: string;
    ready?: boolean;
}
