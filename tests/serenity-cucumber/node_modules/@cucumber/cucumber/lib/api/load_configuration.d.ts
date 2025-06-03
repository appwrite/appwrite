import { IRunEnvironment, IResolvedConfiguration, ILoadConfigurationOptions } from './types';
/**
 * Load user-authored configuration to be used in a test run.
 *
 * @public
 * @param options - Coordinates required to find configuration.
 * @param environment - Project environment.
 */
export declare function loadConfiguration(options?: ILoadConfigurationOptions, environment?: IRunEnvironment): Promise<IResolvedConfiguration>;
