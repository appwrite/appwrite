import { IConfiguration } from './types';
export declare function mergeConfigurations<T = Partial<IConfiguration>>(source: T, ...configurations: Partial<IConfiguration>[]): T;
