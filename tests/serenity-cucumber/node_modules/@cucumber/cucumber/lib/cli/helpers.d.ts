/// <reference types="node" />
/// <reference types="node" />
/// <reference types="node" />
import { EventEmitter } from 'events';
import PickleFilter from '../pickle_filter';
import { EventDataCollector } from '../formatter/helpers';
import { Readable } from 'stream';
import { IdGenerator } from '@cucumber/messages';
import { ISupportCodeLibrary } from '../support_code_library_builder/types';
import { PickleOrder } from '../models/pickle_order';
import { ILogger } from '../logger';
interface IParseGherkinMessageStreamRequest {
    cwd?: string;
    eventBroadcaster: EventEmitter;
    eventDataCollector: EventDataCollector;
    gherkinMessageStream: Readable;
    order: string;
    pickleFilter: PickleFilter;
}
/**
 * Process a stream of envelopes from Gherkin and resolve to an array of filtered, ordered pickle Ids
 *
 * @param eventBroadcaster
 * @param eventDataCollector
 * @param gherkinMessageStream
 * @param order
 * @param pickleFilter
 */
export declare function parseGherkinMessageStream({ eventBroadcaster, eventDataCollector, gherkinMessageStream, order, pickleFilter, }: IParseGherkinMessageStreamRequest): Promise<string[]>;
export declare function orderPickles<T = string>(pickleIds: T[], order: PickleOrder, logger: ILogger): void;
export declare function emitMetaMessage(eventBroadcaster: EventEmitter, env: NodeJS.ProcessEnv): Promise<void>;
export declare function emitSupportCodeMessages({ eventBroadcaster, supportCodeLibrary, newId, }: {
    eventBroadcaster: EventEmitter;
    supportCodeLibrary: ISupportCodeLibrary;
    newId: IdGenerator.NewId;
}): void;
export {};
