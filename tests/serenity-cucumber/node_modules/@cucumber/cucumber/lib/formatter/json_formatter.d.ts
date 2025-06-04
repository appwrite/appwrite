import Formatter, { IFormatterOptions } from './';
import * as messages from '@cucumber/messages';
export interface IJsonFeature {
    description: string;
    elements: IJsonScenario[];
    id: string;
    keyword: string;
    line: number;
    name: string;
    tags: IJsonTag[];
    uri: string;
}
export interface IJsonScenario {
    description: string;
    id: string;
    keyword: string;
    line: number;
    name: string;
    steps: IJsonStep[];
    tags: IJsonTag[];
    type: string;
}
export interface IJsonStep {
    arguments?: any;
    embeddings?: any;
    hidden?: boolean;
    keyword?: string;
    line?: number;
    match?: any;
    name?: string;
    result?: any;
}
export interface IJsonTag {
    name: string;
    line: number;
}
interface IBuildJsonFeatureOptions {
    feature: messages.Feature;
    elements: IJsonScenario[];
    uri: string;
}
interface IBuildJsonScenarioOptions {
    feature: messages.Feature;
    gherkinScenarioMap: Record<string, messages.Scenario>;
    gherkinExampleRuleMap: Record<string, messages.Rule>;
    gherkinScenarioLocationMap: Record<string, messages.Location>;
    pickle: messages.Pickle;
    steps: IJsonStep[];
}
interface IBuildJsonStepOptions {
    isBeforeHook: boolean;
    gherkinStepMap: Record<string, messages.Step>;
    pickleStepMap: Record<string, messages.PickleStep>;
    testStep: messages.TestStep;
    testStepAttachments: messages.Attachment[];
    testStepResult: messages.TestStepResult;
}
export default class JsonFormatter extends Formatter {
    static readonly documentation: string;
    constructor(options: IFormatterOptions);
    convertNameToId(obj: messages.Feature | messages.Pickle): string;
    formatDataTable(dataTable: messages.PickleTable): any;
    formatDocString(docString: messages.PickleDocString, gherkinStep: messages.Step): any;
    formatStepArgument(stepArgument: messages.PickleStepArgument, gherkinStep: messages.Step): any;
    onTestRunFinished(): void;
    getFeatureData({ feature, elements, uri, }: IBuildJsonFeatureOptions): IJsonFeature;
    getScenarioData({ feature, gherkinScenarioLocationMap, gherkinExampleRuleMap, gherkinScenarioMap, pickle, steps, }: IBuildJsonScenarioOptions): IJsonScenario;
    private formatScenarioId;
    getStepData({ isBeforeHook, gherkinStepMap, pickleStepMap, testStep, testStepAttachments, testStepResult, }: IBuildJsonStepOptions): IJsonStep;
    getFeatureTags(feature: messages.Feature): IJsonTag[];
    getScenarioTags({ feature, pickle, gherkinScenarioMap, }: {
        feature: messages.Feature;
        pickle: messages.Pickle;
        gherkinScenarioMap: Record<string, messages.Scenario>;
    }): IJsonTag[];
    private getScenarioTag;
}
export {};
