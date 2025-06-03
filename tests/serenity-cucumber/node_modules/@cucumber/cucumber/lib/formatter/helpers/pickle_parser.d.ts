import * as messages from '@cucumber/messages';
export interface IGetPickleLocationRequest {
    gherkinDocument: messages.GherkinDocument;
    pickle: messages.Pickle;
}
export interface IGetStepKeywordRequest {
    pickleStep: messages.PickleStep;
    gherkinStepMap: Record<string, messages.Step>;
}
export interface IGetScenarioDescriptionRequest {
    pickle: messages.Pickle;
    gherkinScenarioMap: Record<string, messages.Scenario>;
}
export declare function getScenarioDescription({ pickle, gherkinScenarioMap, }: IGetScenarioDescriptionRequest): string;
export declare function getStepKeyword({ pickleStep, gherkinStepMap, }: IGetStepKeywordRequest): string;
export declare function getPickleStepMap(pickle: messages.Pickle): Record<string, messages.PickleStep>;
export declare function getPickleLocation({ gherkinDocument, pickle, }: IGetPickleLocationRequest): messages.Location;
