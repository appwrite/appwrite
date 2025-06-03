export interface IRunRequest {
    argsArray: any[];
    thisArg: any;
    fn: Function;
    timeoutInMilliseconds: number;
}
export interface IRunResponse {
    error?: any;
    result?: any;
}
declare const UserCodeRunner: {
    run({ argsArray, thisArg, fn, timeoutInMilliseconds, }: IRunRequest): Promise<IRunResponse>;
};
export default UserCodeRunner;
