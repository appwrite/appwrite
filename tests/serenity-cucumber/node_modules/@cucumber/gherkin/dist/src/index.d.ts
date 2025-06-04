import generateMessages from './generateMessages';
import makeSourceEnvelope from './makeSourceEnvelope';
import IGherkinOptions from './IGherkinOptions';
import Dialect from './Dialect';
import Parser from './Parser';
import AstBuilder from './AstBuilder';
import TokenScanner from './TokenScanner';
import * as Errors from './Errors';
import compile from './pickles/compile';
import GherkinClassicTokenMatcher from './GherkinClassicTokenMatcher';
import GherkinInMarkdownTokenMatcher from './GherkinInMarkdownTokenMatcher';
declare const dialects: Readonly<{
    [key: string]: Dialect;
}>;
export { generateMessages, makeSourceEnvelope, IGherkinOptions, dialects, Dialect, Parser, AstBuilder, TokenScanner, Errors, GherkinClassicTokenMatcher, GherkinInMarkdownTokenMatcher, compile, };
//# sourceMappingURL=index.d.ts.map