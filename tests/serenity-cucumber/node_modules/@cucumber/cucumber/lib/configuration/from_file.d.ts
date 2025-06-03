import { IConfiguration } from './types';
import { ILogger } from '../logger';
export declare function fromFile(logger: ILogger, cwd: string, file: string, profiles?: string[]): Promise<Partial<IConfiguration>>;
