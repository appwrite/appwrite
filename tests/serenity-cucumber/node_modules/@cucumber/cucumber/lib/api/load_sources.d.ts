import { ILoadSourcesResult, IRunEnvironment, ISourcesCoordinates } from './types';
/**
 * Load and parse features, produce a filtered and ordered test plan and/or parse errors.
 *
 * @public
 * @param coordinates - Coordinates required to find features
 * @param environment - Project environment.
 */
export declare function loadSources(coordinates: ISourcesCoordinates, environment?: IRunEnvironment): Promise<ILoadSourcesResult>;
