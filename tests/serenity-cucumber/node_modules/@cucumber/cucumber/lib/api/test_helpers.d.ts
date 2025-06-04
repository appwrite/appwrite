import { IRunEnvironment } from './types';
export declare function setupEnvironment(): Promise<Partial<IRunEnvironment>>;
export declare function teardownEnvironment(environment: IRunEnvironment): Promise<void>;
