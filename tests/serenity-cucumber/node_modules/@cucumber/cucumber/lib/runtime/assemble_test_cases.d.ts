/// <reference types="node" />
import { EventEmitter } from 'events';
import * as messages from '@cucumber/messages';
import { IdGenerator } from '@cucumber/messages';
import { ISupportCodeLibrary } from '../support_code_library_builder/types';
export declare type IAssembledTestCases = Record<string, messages.TestCase>;
export interface IAssembleTestCasesOptions {
    eventBroadcaster: EventEmitter;
    newId: IdGenerator.NewId;
    pickles: messages.Pickle[];
    supportCodeLibrary: ISupportCodeLibrary;
}
export declare function assembleTestCases({ eventBroadcaster, newId, pickles, supportCodeLibrary, }: IAssembleTestCasesOptions): Promise<IAssembledTestCases>;
