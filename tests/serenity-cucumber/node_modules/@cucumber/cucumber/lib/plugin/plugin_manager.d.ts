import { Plugin, PluginEvents } from './types';
import { IRunEnvironment, IRunOptions } from '../api';
import { ILogger } from '../logger';
export declare class PluginManager {
    private pluginFns;
    private handlers;
    private cleanupFns;
    constructor(pluginFns: Plugin[]);
    private register;
    init(logger: ILogger, configuration: IRunOptions, environment: IRunEnvironment): Promise<void>;
    emit<K extends keyof PluginEvents>(event: K, value: PluginEvents[K]): void;
    cleanup(): Promise<void>;
}
