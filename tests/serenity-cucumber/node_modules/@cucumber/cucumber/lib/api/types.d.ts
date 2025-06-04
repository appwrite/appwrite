/// <reference types="node" />
/// <reference types="node" />
import { ISupportCodeLibrary } from '../support_code_library_builder/types';
import { FormatOptions, IPublishConfig } from '../formatter';
import { PickleOrder } from '../models/pickle_order';
import { IRuntimeOptions } from '../runtime';
import { IConfiguration } from '../configuration';
import { Writable } from 'stream';
/**
 * @public
 */
export interface ILoadConfigurationOptions {
    /**
     * Path to load configuration file from (defaults to `cucumber.(js|cjs|mjs|json)` if omitted).
     */
    file?: string;
    /**
     * Zero or more profile names from which to source configuration (if omitted or empty, the `default` profile will be used).
     */
    profiles?: string[];
    /**
     * Ad-hoc configuration options to be applied over the top of whatever is loaded from the configuration file/profiles.
     */
    provided?: Partial<IConfiguration>;
}
/**
 * @public
 */
export interface IResolvedConfiguration {
    /**
     * The final flat configuration object resolved from the configuration file/profiles plus any extra provided.
     */
    useConfiguration: IConfiguration;
    /**
     * The format that can be passed into `runCucumber`.
     */
    runConfiguration: IRunConfiguration;
}
/**
 * @public
 */
export interface ISourcesCoordinates {
    defaultDialect: string;
    paths: string[];
    names: string[];
    tagExpression: string;
    order: PickleOrder;
}
/**
 * @public
 */
export interface IPlannedPickle {
    name: string;
    uri: string;
    location: {
        line: number;
        column?: number;
    };
}
/**
 * @public
 */
export interface ISourcesError {
    uri: string;
    location: {
        line: number;
        column?: number;
    };
    message: string;
}
/**
 * @public
 */
export interface ILoadSourcesResult {
    plan: IPlannedPickle[];
    errors: ISourcesError[];
}
/**
 * @public
 */
export interface ISupportCodeCoordinates {
    requireModules: string[];
    requirePaths: string[];
    importPaths: string[];
}
/**
 * @public
 */
export interface ILoadSupportOptions {
    sources: ISourcesCoordinates;
    support: ISupportCodeCoordinates;
}
/**
 * @public
 */
export interface IRunOptionsRuntime extends IRuntimeOptions {
    parallel: number;
}
/**
 * @public
 */
export interface IRunOptionsFormats {
    stdout: string;
    files: Record<string, string>;
    publish: IPublishConfig | false;
    options: FormatOptions;
}
/**
 * @public
 */
export interface IRunConfiguration {
    sources: ISourcesCoordinates;
    support: ISupportCodeCoordinates;
    runtime: IRunOptionsRuntime;
    formats: IRunOptionsFormats;
}
/**
 * @public
 */
export type ISupportCodeCoordinatesOrLibrary = ISupportCodeCoordinates | ISupportCodeLibrary;
/**
 * @public
 */
export type { ISupportCodeLibrary };
/**
 * @public
 */
export interface IRunOptions {
    sources: ISourcesCoordinates;
    support: ISupportCodeCoordinatesOrLibrary;
    runtime: IRunOptionsRuntime;
    formats: IRunOptionsFormats;
}
/**
 * Contextual data about the project environment.
 *
 * @public
 */
export interface IRunEnvironment {
    /**
     * Working directory for the project (defaults to `process.cwd()` if omitted).
     */
    cwd?: string;
    /**
     * Writable stream where the test run's main output is written (defaults to `process.stdout` if omitted).
     */
    stdout?: Writable;
    /**
     * Writable stream where the test run's warning/error output is written (defaults to `process.stderr` if omitted).
     */
    stderr?: Writable;
    /**
     * Environment variables (defaults to `process.env` if omitted).
     */
    env?: NodeJS.ProcessEnv;
    /**
     * Whether debug logging is enabled.
     */
    debug?: boolean;
}
/**
 * Result of a Cucumber test run.
 *
 * @public
 */
export interface IRunResult {
    /**
     * Whether the test run was overall successful i.e. no failed scenarios. The exact meaning can vary based on the `strict` configuration option.
     */
    success: boolean;
    /**
     * The support code library that was used in the test run; can be reused in subsequent `runCucumber` calls.
     */
    support: ISupportCodeLibrary;
}
