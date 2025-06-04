import Definition, { IDefinition, IGetInvocationDataResponse, IGetInvocationDataRequest, IDefinitionParameters, IHookDefinitionOptions } from './definition';
import * as messages from '@cucumber/messages';
export default class TestStepHookDefinition extends Definition implements IDefinition {
    readonly tagExpression: string;
    private readonly pickleTagFilter;
    constructor(data: IDefinitionParameters<IHookDefinitionOptions>);
    appliesToTestCase(pickle: messages.Pickle): boolean;
    getInvocationParameters({ hookParameter, }: IGetInvocationDataRequest): Promise<IGetInvocationDataResponse>;
}
