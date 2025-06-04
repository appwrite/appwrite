/// <reference types="node" />
import * as messages from '@cucumber/messages';
import { EventEmitter } from 'events';
export interface ITestCaseAttempt {
    attempt: number;
    willBeRetried: boolean;
    gherkinDocument: messages.GherkinDocument;
    pickle: messages.Pickle;
    stepAttachments: Record<string, messages.Attachment[]>;
    stepResults: Record<string, messages.TestStepResult>;
    testCase: messages.TestCase;
    worstTestStepResult: messages.TestStepResult;
}
export default class EventDataCollector {
    private gherkinDocumentMap;
    private pickleMap;
    private testCaseMap;
    private testCaseAttemptDataMap;
    readonly undefinedParameterTypes: messages.UndefinedParameterType[];
    constructor(eventBroadcaster: EventEmitter);
    getGherkinDocument(uri: string): messages.GherkinDocument;
    getPickle(pickleId: string): messages.Pickle;
    getTestCaseAttempts(): ITestCaseAttempt[];
    getTestCaseAttempt(testCaseStartedId: string): ITestCaseAttempt;
    parseEnvelope(envelope: messages.Envelope): void;
    private initTestCaseAttempt;
    storeAttachment(attachment: messages.Attachment): void;
    storeTestStepResult({ testCaseStartedId, testStepId, testStepResult, }: messages.TestStepFinished): void;
    storeTestCaseResult({ testCaseStartedId, willBeRetried, }: messages.TestCaseFinished): void;
}
