import AstNode from './AstNode';
import * as messages from '@cucumber/messages';
import { RuleType, TokenType } from './Parser';
import IToken from './IToken';
import { IAstBuilder } from './IAstBuilder';
export default class AstBuilder implements IAstBuilder<AstNode, TokenType, RuleType> {
    stack: AstNode[];
    comments: messages.Comment[];
    readonly newId: messages.IdGenerator.NewId;
    constructor(newId: messages.IdGenerator.NewId);
    reset(): void;
    startRule(ruleType: RuleType): void;
    endRule(): void;
    build(token: IToken<TokenType>): void;
    getResult(): any;
    currentNode(): AstNode;
    getLocation(token: IToken<TokenType>, column?: number): messages.Location;
    getTags(node: AstNode): messages.Tag[];
    getCells(tableRowToken: IToken<TokenType>): {
        location: messages.Location;
        value: string;
    }[];
    getDescription(node: AstNode): any;
    getSteps(node: AstNode): any[];
    getTableRows(node: AstNode): {
        id: string;
        location: messages.Location;
        cells: {
            location: messages.Location;
            value: string;
        }[];
    }[];
    ensureCellCount(rows: messages.TableRow[]): void;
    transformNode(node: AstNode): string | messages.GherkinDocument | AstNode | {
        id: string;
        location: messages.Location;
        cells: {
            location: messages.Location;
            value: string;
        }[];
    }[] | messages.Step | messages.DocString | messages.DataTable | messages.Background | messages.Scenario | messages.Examples | messages.Feature | messages.Rule;
}
//# sourceMappingURL=AstBuilder.d.ts.map