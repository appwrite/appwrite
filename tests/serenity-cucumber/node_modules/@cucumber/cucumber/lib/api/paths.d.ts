import { ISourcesCoordinates, ISupportCodeCoordinates } from './types';
import { ILogger } from '../logger';
export declare function resolvePaths(logger: ILogger, cwd: string, sources: Pick<ISourcesCoordinates, 'paths'>, support?: ISupportCodeCoordinates): Promise<{
    unexpandedFeaturePaths: string[];
    featurePaths: string[];
    requirePaths: string[];
    importPaths: string[];
}>;
