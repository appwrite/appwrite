import { ILoadSupportOptions, IRunEnvironment } from './types';
import { ISupportCodeLibrary } from '../support_code_library_builder/types';
/**
 * Load support code for use in test runs.
 *
 * @public
 * @param options - Subset of `IRunnableConfiguration` required to find the support code.
 * @param environment - Project environment.
 */
export declare function loadSupport(options: ILoadSupportOptions, environment?: IRunEnvironment): Promise<ISupportCodeLibrary>;
