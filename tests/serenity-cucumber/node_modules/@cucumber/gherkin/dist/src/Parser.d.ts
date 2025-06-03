import * as messages from '@cucumber/messages';
import ITokenMatcher from './ITokenMatcher';
import GherkinLine from './GherkinLine';
import IToken, { Item } from './IToken';
import { IAstBuilder } from './IAstBuilder';
export declare class Token implements IToken<TokenType> {
    readonly line: GherkinLine;
    readonly location: messages.Location;
    isEof: boolean;
    matchedText?: string;
    matchedType: TokenType;
    matchedItems: readonly Item[];
    matchedKeyword: string;
    matchedIndent: number;
    matchedGherkinDialect: string;
    matchedKeywordType: messages.StepKeywordType;
    constructor(line: GherkinLine, location: messages.Location);
    getTokenValue(): string;
    detach(): void;
}
export declare enum TokenType {
    None = 0,
    EOF = 1,
    Empty = 2,
    Comment = 3,
    TagLine = 4,
    FeatureLine = 5,
    RuleLine = 6,
    BackgroundLine = 7,
    ScenarioLine = 8,
    ExamplesLine = 9,
    StepLine = 10,
    DocStringSeparator = 11,
    TableRow = 12,
    Language = 13,
    Other = 14
}
export declare enum RuleType {
    None = 0,
    _EOF = 1,
    _Empty = 2,
    _Comment = 3,
    _TagLine = 4,
    _FeatureLine = 5,
    _RuleLine = 6,
    _BackgroundLine = 7,
    _ScenarioLine = 8,
    _ExamplesLine = 9,
    _StepLine = 10,
    _DocStringSeparator = 11,
    _TableRow = 12,
    _Language = 13,
    _Other = 14,
    GherkinDocument = 15,
    Feature = 16,
    FeatureHeader = 17,
    Rule = 18,
    RuleHeader = 19,
    Background = 20,
    ScenarioDefinition = 21,
    Scenario = 22,
    ExamplesDefinition = 23,
    Examples = 24,
    ExamplesTable = 25,
    Step = 26,
    StepArg = 27,
    DataTable = 28,
    DocString = 29,
    Tags = 30,
    DescriptionHelper = 31,
    Description = 32
}
export default class Parser<AstNode> {
    private readonly builder;
    private readonly tokenMatcher;
    stopAtFirstError: boolean;
    private context;
    constructor(builder: IAstBuilder<AstNode, TokenType, RuleType>, tokenMatcher: ITokenMatcher<TokenType>);
    parse(gherkinSource: string): messages.GherkinDocument;
    private addError;
    private startRule;
    private endRule;
    private build;
    private getResult;
    private handleAstError;
    private handleExternalError;
    private readToken;
    private matchToken;
    private matchTokenAt_0;
    private matchTokenAt_1;
    private matchTokenAt_2;
    private matchTokenAt_3;
    private matchTokenAt_4;
    private matchTokenAt_5;
    private matchTokenAt_6;
    private matchTokenAt_7;
    private matchTokenAt_8;
    private matchTokenAt_9;
    private matchTokenAt_10;
    private matchTokenAt_11;
    private matchTokenAt_12;
    private matchTokenAt_13;
    private matchTokenAt_14;
    private matchTokenAt_15;
    private matchTokenAt_16;
    private matchTokenAt_17;
    private matchTokenAt_18;
    private matchTokenAt_19;
    private matchTokenAt_20;
    private matchTokenAt_21;
    private matchTokenAt_22;
    private matchTokenAt_23;
    private matchTokenAt_24;
    private matchTokenAt_25;
    private matchTokenAt_26;
    private matchTokenAt_27;
    private matchTokenAt_28;
    private matchTokenAt_29;
    private matchTokenAt_30;
    private matchTokenAt_31;
    private matchTokenAt_32;
    private matchTokenAt_33;
    private matchTokenAt_34;
    private matchTokenAt_35;
    private matchTokenAt_36;
    private matchTokenAt_37;
    private matchTokenAt_38;
    private matchTokenAt_39;
    private matchTokenAt_40;
    private matchTokenAt_41;
    private matchTokenAt_43;
    private matchTokenAt_44;
    private matchTokenAt_45;
    private matchTokenAt_46;
    private matchTokenAt_47;
    private matchTokenAt_48;
    private matchTokenAt_49;
    private matchTokenAt_50;
    private match_EOF;
    private match_Empty;
    private match_Comment;
    private match_TagLine;
    private match_FeatureLine;
    private match_RuleLine;
    private match_BackgroundLine;
    private match_ScenarioLine;
    private match_ExamplesLine;
    private match_StepLine;
    private match_DocStringSeparator;
    private match_TableRow;
    private match_Language;
    private match_Other;
    private lookahead_0;
    private lookahead_1;
}
//# sourceMappingURL=Parser.d.ts.map