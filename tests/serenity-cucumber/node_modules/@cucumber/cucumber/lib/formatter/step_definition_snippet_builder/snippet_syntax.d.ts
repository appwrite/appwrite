import { GeneratedExpression } from '@cucumber/cucumber-expressions';
export declare enum SnippetInterface {
    AsyncAwait = "async-await",
    Callback = "callback",
    Promise = "promise",
    Synchronous = "synchronous"
}
export interface ISnippetSyntaxBuildOptions {
    comment: string;
    functionName: string;
    generatedExpressions: readonly GeneratedExpression[];
    stepParameterNames: string[];
}
export interface ISnippetSnytax {
    build: (options: ISnippetSyntaxBuildOptions) => string;
}
