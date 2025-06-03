import { ICoordinatorReport, IWorkerCommand, IWorkerCommandInitialize, IWorkerCommandRun } from './command_types';
type IExitFunction = (exitCode: number, error?: Error, message?: string) => void;
type IMessageSender = (command: ICoordinatorReport) => void;
export default class Worker {
    private readonly cwd;
    private readonly exit;
    private readonly id;
    private readonly eventBroadcaster;
    private filterStacktraces;
    private readonly newId;
    private readonly sendMessage;
    private supportCodeLibrary;
    private worldParameters;
    private runTestRunHooks;
    constructor({ cwd, exit, id, sendMessage, }: {
        cwd: string;
        exit: IExitFunction;
        id: string;
        sendMessage: IMessageSender;
    });
    initialize({ filterStacktraces, requireModules, requirePaths, importPaths, supportCodeIds, options, }: IWorkerCommandInitialize): Promise<void>;
    finalize(): Promise<void>;
    receiveMessage(message: IWorkerCommand): Promise<void>;
    runTestCase({ gherkinDocument, pickle, testCase, elapsed, retries, skip, }: IWorkerCommandRun): Promise<void>;
}
export {};
