/// <reference types="node" />
/// <reference types="node" />
import { TestStepResultStatus } from '@cucumber/messages';
import { Writable } from 'stream';
export type IColorFn = (text: string) => string;
export interface IColorFns {
    forStatus: (status: TestStepResultStatus) => IColorFn;
    location: IColorFn;
    tag: IColorFn;
    diffAdded: IColorFn;
    diffRemoved: IColorFn;
    errorMessage: IColorFn;
    errorStack: IColorFn;
}
export default function getColorFns(stream: Writable, env: NodeJS.ProcessEnv, enabled?: boolean): IColorFns;
