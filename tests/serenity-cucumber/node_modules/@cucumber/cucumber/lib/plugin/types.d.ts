import { IRunEnvironment, IRunOptions } from '../api';
import { Envelope } from '@cucumber/messages';
import { ILogger } from '../logger';
export interface PluginEvents {
    message: Envelope;
}
export interface PluginContext {
    on: <K extends keyof PluginEvents>(event: K, handler: (value: PluginEvents[K]) => void) => void;
    logger: ILogger;
    configuration: IRunOptions;
    environment: IRunEnvironment;
}
export type PluginCleanup = () => any | void | Promise<any | void>;
export type Plugin = (context: PluginContext) => Promise<PluginCleanup | void>;
