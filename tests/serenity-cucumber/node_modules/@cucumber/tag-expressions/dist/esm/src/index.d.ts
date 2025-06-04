/**
 * Parses infix boolean expression (using Dijkstra's Shunting Yard algorithm)
 * and builds a tree of expressions. The root node of the expression is returned.
 *
 * This expression can be evaluated by passing in an array of literals that resolve to true
 */
export default function parse(infix: string): Node;
interface Node {
    evaluate(variables: string[]): boolean;
}
export {};
//# sourceMappingURL=index.d.ts.map