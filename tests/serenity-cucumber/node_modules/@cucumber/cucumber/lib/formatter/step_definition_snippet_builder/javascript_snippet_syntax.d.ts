import { ISnippetSnytax, ISnippetSyntaxBuildOptions, SnippetInterface } from './snippet_syntax';
export default class JavaScriptSnippetSyntax implements ISnippetSnytax {
    private readonly snippetInterface;
    constructor(snippetInterface: SnippetInterface);
    build({ comment, generatedExpressions, functionName, stepParameterNames, }: ISnippetSyntaxBuildOptions): string;
    private escapeSpecialCharacters;
}
