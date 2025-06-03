import * as messages from '@cucumber/messages';
export default class Query {
    private readonly sources;
    private readonly sourceByUri;
    private readonly gherkinDocuments;
    private readonly pickles;
    private readonly locationByAstNodeId;
    private readonly gherkinStepByAstNodeId;
    private readonly pickleIdsMapByUri;
    private readonly pickleIdsByAstNodeId;
    private readonly pickleStepIdsByAstNodeId;
    private readonly featureByUriLine;
    private readonly backgroundByUriLine;
    private readonly ruleByUriLine;
    private readonly scenarioByUriLine;
    private readonly examplesByUriLine;
    private readonly stepByUriLine;
    /**
     * Gets the location (line and column) of an AST node.
     * @param astNodeId
     */
    getLocation(astNodeId: string): messages.Location;
    getSources(): readonly messages.Source[];
    getGherkinDocuments(): readonly messages.GherkinDocument[];
    getPickles(): readonly messages.Pickle[];
    getSource(uri: string): messages.Source | undefined;
    getFeature(uri: string, line: number): messages.Feature | undefined;
    getBackground(uri: string, line: number): messages.Background | undefined;
    getRule(uri: string, line: number): messages.Rule | undefined;
    getScenario(uri: string, line: number): messages.Scenario | undefined;
    getExamples(uri: string, line: number): messages.Examples | undefined;
    getStep(uri: string, line: number): messages.Step | undefined;
    /**
     * Gets all the pickle IDs
     * @param uri - the URI of the document
     * @param astNodeId - optionally restrict results to a particular AST node
     */
    getPickleIds(uri: string, astNodeId?: string): readonly string[];
    getPickleStepIds(astNodeId: string): readonly string[];
    update(message: messages.Envelope): Query;
    private updateGherkinFeature;
    private updateGherkinBackground;
    private updateGherkinRule;
    private updateGherkinScenario;
    private updateGherkinExamples;
    private updateGherkinStep;
    private updatePickle;
    private updatePickleSteps;
}
//# sourceMappingURL=Query.d.ts.map