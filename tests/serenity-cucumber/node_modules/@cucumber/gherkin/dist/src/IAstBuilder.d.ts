import * as messages from '@cucumber/messages';
import IToken from './IToken';
export interface IAstBuilder<AstNode, TokenType, RuleType> {
    stack: AstNode[];
    comments: messages.Comment[];
    newId: messages.IdGenerator.NewId;
    reset(): void;
    startRule(ruleType: RuleType): void;
    endRule(): void;
    build(token: IToken<TokenType>): void;
    getResult(): any;
    currentNode(): any;
    getLocation(token: IToken<TokenType>, column?: number): messages.Location;
    getTags(node: AstNode): readonly messages.Tag[];
    getCells(tableRowToken: IToken<TokenType>): readonly messages.TableCell[];
    getDescription(node: AstNode): any;
    getSteps(node: AstNode): any[];
    getTableRows(node: AstNode): readonly messages.TableRow[] | undefined;
    ensureCellCount(rows: readonly messages.TableRow[]): void;
    transformNode(node: AstNode): any;
}
//# sourceMappingURL=IAstBuilder.d.ts.map