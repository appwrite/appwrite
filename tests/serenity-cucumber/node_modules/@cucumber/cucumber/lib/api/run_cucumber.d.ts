import { Envelope } from '@cucumber/messages';
import { IRunOptions, IRunEnvironment, IRunResult } from './types';
/**
 * Execute a Cucumber test run.
 *
 * @public
 * @param configuration - Configuration loaded from `loadConfiguration`.
 * @param environment - Project environment.
 * @param onMessage - Callback fired each time Cucumber emits a message.
 */
export declare function runCucumber(configuration: IRunOptions, environment?: IRunEnvironment, onMessage?: (message: Envelope) => void): Promise<IRunResult>;
