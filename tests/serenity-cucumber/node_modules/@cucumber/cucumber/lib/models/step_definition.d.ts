import Definition, { IDefinition, IGetInvocationDataRequest, IGetInvocationDataResponse, IStepDefinitionParameters } from './definition';
import { Expression } from '@cucumber/cucumber-expressions';
import { GherkinStepKeyword } from './gherkin_step_keyword';
export default class StepDefinition extends Definition implements IDefinition {
    readonly keyword: GherkinStepKeyword;
    readonly pattern: string | RegExp;
    readonly expression: Expression;
    constructor(data: IStepDefinitionParameters);
    getInvocationParameters({ step, world, }: IGetInvocationDataRequest): Promise<IGetInvocationDataResponse>;
    matchesStepName(stepName: string): boolean;
}
