import * as messages from '@cucumber/messages';
import IGherkinDocument = messages.GherkinDocument;
import IPickle = messages.Pickle;
export interface IPickleFilterOptions {
    cwd: string;
    featurePaths?: string[];
    names?: string[];
    tagExpression?: string;
}
export interface IMatchesAnyLineRequest {
    gherkinDocument: messages.GherkinDocument;
    pickle: messages.Pickle;
}
export default class PickleFilter {
    private readonly lineFilter;
    private readonly nameFilter;
    private readonly tagFilter;
    constructor({ cwd, featurePaths, names, tagExpression, }: IPickleFilterOptions);
    matches({ gherkinDocument, pickle, }: {
        gherkinDocument: IGherkinDocument;
        pickle: IPickle;
    }): boolean;
}
export declare class PickleLineFilter {
    private readonly featureUriToLinesMapping;
    constructor(cwd: string, featurePaths?: string[]);
    getFeatureUriToLinesMapping({ cwd, featurePaths, }: {
        cwd: string;
        featurePaths: string[];
    }): Record<string, number[]>;
    matchesAnyLine({ gherkinDocument, pickle }: IMatchesAnyLineRequest): boolean;
}
export declare class PickleNameFilter {
    private readonly names;
    constructor(names?: string[]);
    matchesAnyName(pickle: messages.Pickle): boolean;
}
export declare class PickleTagFilter {
    private readonly tagExpressionNode;
    constructor(tagExpression: string);
    matchesAllTagExpressions(pickle: messages.Pickle): boolean;
}
