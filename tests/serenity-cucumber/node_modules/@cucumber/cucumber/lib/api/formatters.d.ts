/// <reference types="node" />
/// <reference types="node" />
import { IFormatterStream } from '../formatter';
import { EventEmitter } from 'events';
import { EventDataCollector } from '../formatter/helpers';
import { ISupportCodeLibrary } from '../support_code_library_builder/types';
import { IRunOptionsFormats } from './types';
import { ILogger } from '../logger';
export declare function initializeFormatters({ env, cwd, stdout, logger, onStreamError, eventBroadcaster, eventDataCollector, configuration, supportCodeLibrary, }: {
    env: NodeJS.ProcessEnv;
    cwd: string;
    stdout: IFormatterStream;
    stderr: IFormatterStream;
    logger: ILogger;
    onStreamError: () => void;
    eventBroadcaster: EventEmitter;
    eventDataCollector: EventDataCollector;
    configuration: IRunOptionsFormats;
    supportCodeLibrary: ISupportCodeLibrary;
}): Promise<() => Promise<void>>;
