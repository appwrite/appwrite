import { TestStepResult } from '@cucumber/messages';
export declare function formatError(error: Error, filterStackTraces: boolean): Pick<TestStepResult, 'message' | 'exception'>;
