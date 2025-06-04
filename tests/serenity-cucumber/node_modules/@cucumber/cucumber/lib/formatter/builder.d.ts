/// <reference types="node" />
/// <reference types="node" />
/// <reference types="node" />
/// <reference types="node" />
import StepDefinitionSnippetBuilder from './step_definition_snippet_builder';
import { ISupportCodeLibrary } from '../support_code_library_builder/types';
import Formatter, { FormatOptions, IFormatterCleanupFn, IFormatterLogFn } from '.';
import { EventEmitter } from 'events';
import EventDataCollector from './helpers/event_data_collector';
import { Writable as WritableStream } from 'stream';
import { SnippetInterface } from './step_definition_snippet_builder/snippet_syntax';
interface IGetStepDefinitionSnippetBuilderOptions {
    cwd: string;
    snippetInterface?: SnippetInterface;
    snippetSyntax?: string;
    supportCodeLibrary: ISupportCodeLibrary;
}
export interface IBuildOptions {
    env: NodeJS.ProcessEnv;
    cwd: string;
    eventBroadcaster: EventEmitter;
    eventDataCollector: EventDataCollector;
    log: IFormatterLogFn;
    parsedArgvOptions: FormatOptions;
    stream: WritableStream;
    cleanup: IFormatterCleanupFn;
    supportCodeLibrary: ISupportCodeLibrary;
}
declare const FormatterBuilder: {
    build(type: string, options: IBuildOptions): Promise<Formatter>;
    getConstructorByType(type: string, cwd: string): Promise<typeof Formatter>;
    getStepDefinitionSnippetBuilder({ cwd, snippetInterface, snippetSyntax, supportCodeLibrary, }: IGetStepDefinitionSnippetBuilderOptions): Promise<StepDefinitionSnippetBuilder>;
    loadCustomClass(type: 'formatter' | 'syntax', descriptor: string, cwd: string): Promise<any>;
    loadFile(urlOrName: URL | string): Promise<any>;
    resolveConstructor(ImportedCode: any): any;
};
export default FormatterBuilder;
