import * as messages from '@cucumber/messages';
export interface IFilters {
    acceptScenario?: (scenario: messages.Scenario) => boolean;
    acceptStep?: (step: messages.Step) => boolean;
    acceptBackground?: (background: messages.Background) => boolean;
    acceptRule?: (rule: messages.Rule) => boolean;
    acceptFeature?: (feature: messages.Feature) => boolean;
}
export interface IHandlers {
    handleStep?: (step: messages.Step) => void;
    handleScenario?: (scenario: messages.Scenario) => void;
    handleBackground?: (background: messages.Background) => void;
    handleRule?: (rule: messages.Rule) => void;
    handleFeature?: (feature: messages.Feature) => void;
}
export declare const rejectAllFilters: IFilters;
export default class GherkinDocumentWalker {
    private readonly filters;
    private readonly handlers;
    constructor(filters?: IFilters, handlers?: IHandlers);
    walkGherkinDocument(gherkinDocument: messages.GherkinDocument): messages.GherkinDocument | null;
    protected walkFeature(feature: messages.Feature): messages.Feature;
    private copyFeature;
    private copyTags;
    private filterFeatureChildren;
    private walkFeatureChildren;
    protected walkRule(rule: messages.Rule): messages.Rule;
    private copyRule;
    private filterRuleChildren;
    private walkRuleChildren;
    protected walkBackground(background: messages.Background): messages.Background;
    private copyBackground;
    protected walkScenario(scenario: messages.Scenario): messages.Scenario;
    private copyScenario;
    protected walkAllSteps(steps: readonly messages.Step[]): messages.Step[];
    protected walkStep(step: messages.Step): messages.Step;
    private copyStep;
}
//# sourceMappingURL=GherkinDocumentWalker.d.ts.map