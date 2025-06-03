import { PluginManager } from '../plugin';
import { IRunEnvironment, IRunOptions } from './types';
import { ILogger } from '../logger';
export declare function initializePlugins(logger: ILogger, configuration: IRunOptions, environment: IRunEnvironment): Promise<PluginManager>;
