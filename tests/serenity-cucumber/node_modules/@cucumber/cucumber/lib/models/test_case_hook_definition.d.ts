import Definition, { IDefinition, IDefinitionParameters, IGetInvocationDataRequest, IGetInvocationDataResponse, IHookDefinitionOptions } from './definition';
import * as messages from '@cucumber/messages';
export default class TestCaseHookDefinition extends Definition implements IDefinition {
    readonly name: string;
    readonly tagExpression: string;
    private readonly pickleTagFilter;
    constructor(data: IDefinitionParameters<IHookDefinitionOptions>);
    appliesToTestCase(pickle: messages.Pickle): boolean;
    getInvocationParameters({ hookParameter, }: IGetInvocationDataRequest): Promise<IGetInvocationDataResponse>;
}
