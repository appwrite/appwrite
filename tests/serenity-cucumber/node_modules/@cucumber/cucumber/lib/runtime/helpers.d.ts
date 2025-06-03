import StepDefinition from '../models/step_definition';
import * as messages from '@cucumber/messages';
import { IRuntimeOptions } from '.';
export declare function getAmbiguousStepException(stepDefinitions: StepDefinition[]): string;
export declare function retriesForPickle(pickle: messages.Pickle, options: IRuntimeOptions): number;
export declare function shouldCauseFailure(status: messages.TestStepResultStatus, options: IRuntimeOptions): boolean;
