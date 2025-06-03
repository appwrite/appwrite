import ITokenMatcher from './ITokenMatcher';
import { Token, TokenType } from './Parser';
import * as messages from '@cucumber/messages';
export default class GherkinInMarkdownTokenMatcher implements ITokenMatcher<TokenType> {
    private readonly defaultDialectName;
    private dialect;
    private dialectName;
    private readonly nonStarStepKeywords;
    private readonly stepRegexp;
    private readonly headerRegexp;
    private activeDocStringSeparator;
    private indentToRemove;
    private matchedFeatureLine;
    private keywordTypesMap;
    constructor(defaultDialectName?: string);
    changeDialect(newDialectName: string, location?: messages.Location): void;
    initializeKeywordTypes(): void;
    match_Language(token: Token): boolean;
    match_Empty(token: Token): boolean;
    match_Other(token: Token): boolean;
    match_Comment(token: Token): boolean;
    match_DocStringSeparator(token: Token): boolean;
    match_EOF(token: Token): boolean;
    match_FeatureLine(token: Token): boolean;
    match_BackgroundLine(token: Token): boolean;
    match_RuleLine(token: Token): boolean;
    match_ScenarioLine(token: Token): boolean;
    match_ExamplesLine(token: Token): boolean;
    match_StepLine(token: Token): boolean;
    matchTitleLine(prefix: KeywordPrefix, keywords: readonly string[], keywordSuffix: ':' | '', token: Token, matchedType: TokenType): boolean;
    setTokenMatched(token: Token, indent: number | null, matched: boolean): boolean;
    match_TableRow(token: Token): boolean;
    private isGfmTableSeparator;
    match_TagLine(token: Token): boolean;
    reset(): void;
}
declare enum KeywordPrefix {
    BULLET = "^(\\s*[*+-]\\s*)",
    HEADER = "^(#{1,6}\\s)"
}
export {};
//# sourceMappingURL=GherkinInMarkdownTokenMatcher.d.ts.map