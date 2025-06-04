import IToken, { IGherkinLine, Item } from './IToken';
import * as messages from '@cucumber/messages';
import { TokenType } from './Parser';
import ITokenMatcher from './ITokenMatcher';
export default class GherkinClassicTokenMatcher implements ITokenMatcher<TokenType> {
    private readonly defaultDialectName;
    private dialect;
    private dialectName;
    private activeDocStringSeparator;
    private indentToRemove;
    private keywordTypesMap;
    constructor(defaultDialectName?: string);
    changeDialect(newDialectName: string, location?: messages.Location): void;
    reset(): void;
    initializeKeywordTypes(): void;
    match_TagLine(token: IToken<TokenType>): boolean;
    match_FeatureLine(token: IToken<TokenType>): boolean;
    match_ScenarioLine(token: IToken<TokenType>): boolean;
    match_BackgroundLine(token: IToken<TokenType>): boolean;
    match_ExamplesLine(token: IToken<TokenType>): boolean;
    match_RuleLine(token: IToken<TokenType>): boolean;
    match_TableRow(token: IToken<TokenType>): boolean;
    match_Empty(token: IToken<TokenType>): boolean;
    match_Comment(token: IToken<TokenType>): boolean;
    match_Language(token: IToken<TokenType>): boolean;
    match_DocStringSeparator(token: IToken<TokenType>): boolean;
    _match_DocStringSeparator(token: IToken<TokenType>, separator: string, isOpen: boolean): boolean;
    match_EOF(token: IToken<TokenType>): boolean;
    match_StepLine(token: IToken<TokenType>): boolean;
    match_Other(token: IToken<TokenType>): boolean;
    getTags(line: IGherkinLine): readonly Item[];
    private matchTitleLine;
    private setTokenMatched;
    private unescapeDocString;
}
//# sourceMappingURL=GherkinClassicTokenMatcher.d.ts.map