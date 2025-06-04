import * as messages from '@cucumber/messages';
export interface IPickleStepArgumentFunctionMap<T> {
    dataTable: (arg: messages.PickleTable) => T;
    docString: (arg: messages.PickleDocString) => T;
}
export declare function parseStepArgument<T>(arg: messages.PickleStepArgument, mapping: IPickleStepArgumentFunctionMap<T>): T;
