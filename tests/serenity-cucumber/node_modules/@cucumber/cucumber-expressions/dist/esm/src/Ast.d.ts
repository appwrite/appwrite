export declare function symbolOf(token: TokenType): string;
export declare function purposeOf(token: TokenType): string;
export interface Located {
    readonly start: number;
    readonly end: number;
}
export declare class Node implements Located {
    readonly type: NodeType;
    readonly nodes: readonly Node[] | undefined;
    private readonly token;
    readonly start: number;
    readonly end: number;
    constructor(type: NodeType, nodes: readonly Node[] | undefined, token: string | undefined, start: number, end: number);
    text(): string;
}
export declare enum NodeType {
    text = "TEXT_NODE",
    optional = "OPTIONAL_NODE",
    alternation = "ALTERNATION_NODE",
    alternative = "ALTERNATIVE_NODE",
    parameter = "PARAMETER_NODE",
    expression = "EXPRESSION_NODE"
}
export declare class Token implements Located {
    readonly type: TokenType;
    readonly text: string;
    readonly start: number;
    readonly end: number;
    constructor(type: TokenType, text: string, start: number, end: number);
    static isEscapeCharacter(codePoint: string): boolean;
    static canEscape(codePoint: string): boolean;
    static typeOf(codePoint: string): TokenType;
}
export declare enum TokenType {
    startOfLine = "START_OF_LINE",
    endOfLine = "END_OF_LINE",
    whiteSpace = "WHITE_SPACE",
    beginOptional = "BEGIN_OPTIONAL",
    endOptional = "END_OPTIONAL",
    beginParameter = "BEGIN_PARAMETER",
    endParameter = "END_PARAMETER",
    alternation = "ALTERNATION",
    text = "TEXT"
}
//# sourceMappingURL=Ast.d.ts.map