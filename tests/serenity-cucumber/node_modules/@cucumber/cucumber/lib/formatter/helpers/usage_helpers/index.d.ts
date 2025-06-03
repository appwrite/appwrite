import * as messages from '@cucumber/messages';
import StepDefinition from '../../../models/step_definition';
import EventDataCollector from '../event_data_collector';
export interface IUsageMatch {
    duration?: messages.Duration;
    line: number;
    text: string;
    uri: string;
}
export interface IUsage {
    code: string;
    line: number;
    matches: IUsageMatch[];
    meanDuration?: messages.Duration;
    pattern: string;
    patternType: string;
    uri: string;
}
export interface IGetUsageRequest {
    eventDataCollector: EventDataCollector;
    stepDefinitions: StepDefinition[];
}
export declare function getUsage({ stepDefinitions, eventDataCollector, }: IGetUsageRequest): IUsage[];
