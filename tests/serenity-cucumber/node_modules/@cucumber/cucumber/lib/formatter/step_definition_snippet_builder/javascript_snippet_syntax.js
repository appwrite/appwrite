"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
const snippet_syntax_1 = require("./snippet_syntax");
const CALLBACK_NAME = 'callback';
class JavaScriptSnippetSyntax {
    constructor(snippetInterface) {
        this.snippetInterface = snippetInterface;
    }
    build({ comment, generatedExpressions, functionName, stepParameterNames, }) {
        let functionKeyword = 'function ';
        if (this.snippetInterface === snippet_syntax_1.SnippetInterface.AsyncAwait) {
            functionKeyword = 'async ' + functionKeyword;
        }
        let implementation;
        if (this.snippetInterface === snippet_syntax_1.SnippetInterface.Callback) {
            implementation = `${CALLBACK_NAME}(null, 'pending');`;
        }
        else if (this.snippetInterface === snippet_syntax_1.SnippetInterface.Promise) {
            implementation = "return Promise.resolve('pending');";
        }
        else {
            implementation = "return 'pending';";
        }
        const definitionChoices = generatedExpressions.map((generatedExpression, index) => {
            const prefix = index === 0 ? '' : '// ';
            const allParameterNames = generatedExpression.parameterNames.concat(stepParameterNames);
            if (this.snippetInterface === snippet_syntax_1.SnippetInterface.Callback) {
                allParameterNames.push(CALLBACK_NAME);
            }
            return `${prefix + functionName}('${this.escapeSpecialCharacters(generatedExpression)}', ${functionKeyword}(${allParameterNames.join(', ')}) {\n`;
        });
        return (`${definitionChoices.join('')}  // ${comment}\n` +
            `  ${implementation}\n` +
            '});');
    }
    escapeSpecialCharacters(generatedExpression) {
        let source = generatedExpression.source;
        // double up any backslashes because we're in a javascript string
        source = source.replace(/\\/g, '\\\\');
        // escape any single quotes because that's our quote delimiter
        source = source.replace(/'/g, "\\'");
        return source;
    }
}
exports.default = JavaScriptSnippetSyntax;
//# sourceMappingURL=javascript_snippet_syntax.js.map