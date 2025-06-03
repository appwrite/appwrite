/// <reference types="node" />
import { IRuntime } from '../runtime';
import { EventEmitter } from 'events';
import { EventDataCollector } from '../formatter/helpers';
import { IdGenerator } from '@cucumber/messages';
import { ISupportCodeLibrary } from '../support_code_library_builder/types';
import { IRunOptionsRuntime } from './types';
import { ILogger } from '../logger';
export declare function makeRuntime({ cwd, logger, eventBroadcaster, eventDataCollector, pickleIds, newId, supportCodeLibrary, requireModules, requirePaths, importPaths, options: { parallel, ...options }, }: {
    cwd: string;
    logger: ILogger;
    eventBroadcaster: EventEmitter;
    eventDataCollector: EventDataCollector;
    newId: IdGenerator.NewId;
    pickleIds: string[];
    supportCodeLibrary: ISupportCodeLibrary;
    requireModules: string[];
    requirePaths: string[];
    importPaths: string[];
    options: IRunOptionsRuntime;
}): IRuntime;
