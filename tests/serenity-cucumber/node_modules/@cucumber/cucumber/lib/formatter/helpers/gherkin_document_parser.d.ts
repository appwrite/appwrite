import * as messages from '@cucumber/messages';
export declare function getGherkinStepMap(gherkinDocument: messages.GherkinDocument): Record<string, messages.Step>;
export declare function getGherkinScenarioMap(gherkinDocument: messages.GherkinDocument): Record<string, messages.Scenario>;
export declare function getGherkinExampleRuleMap(gherkinDocument: messages.GherkinDocument): Record<string, messages.Rule>;
export declare function getGherkinScenarioLocationMap(gherkinDocument: messages.GherkinDocument): Record<string, messages.Location>;
