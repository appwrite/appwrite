/// <reference types="node" />
import { IFormatterStream } from '../formatter';
export interface ICliRunResult {
    shouldExitImmediately: boolean;
    success: boolean;
}
export default class Cli {
    private readonly argv;
    private readonly cwd;
    private readonly stdout;
    private readonly stderr;
    private readonly env;
    constructor({ argv, cwd, stdout, stderr, env, }: {
        argv: string[];
        cwd: string;
        stdout: IFormatterStream;
        stderr?: IFormatterStream;
        env: NodeJS.ProcessEnv;
    });
    run(): Promise<ICliRunResult>;
}
