import { DefineStepPattern, IDefineStepOptions } from './types';
interface IDefineStepArguments {
    pattern?: DefineStepPattern;
    options?: IDefineStepOptions;
    code?: Function;
}
export default function validateArguments({ args, fnName, location, }: {
    args?: IDefineStepArguments;
    fnName: string;
    location: string;
}): void;
export {};
