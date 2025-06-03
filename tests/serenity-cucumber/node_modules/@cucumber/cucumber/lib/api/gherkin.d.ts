import { Envelope, GherkinDocument, IdGenerator, Location, ParseError, Pickle } from '@cucumber/messages';
import { ISourcesCoordinates } from './types';
import { ILogger } from '../logger';
interface PickleWithDocument {
    gherkinDocument: GherkinDocument;
    location: Location;
    pickle: Pickle;
}
export declare function getFilteredPicklesAndErrors({ newId, cwd, logger, unexpandedFeaturePaths, featurePaths, coordinates, onEnvelope, }: {
    newId: IdGenerator.NewId;
    cwd: string;
    logger: ILogger;
    unexpandedFeaturePaths: string[];
    featurePaths: string[];
    coordinates: ISourcesCoordinates;
    onEnvelope?: (envelope: Envelope) => void;
}): Promise<{
    filteredPickles: PickleWithDocument[];
    parseErrors: ParseError[];
}>;
export {};
